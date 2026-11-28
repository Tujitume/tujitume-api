<?php

namespace App\Service\Program;

use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Organizations\Organization;
use App\Service\LiprMpesa\LiprCallback;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Notification\EmailService;
use App\Service\Program\OrganizationMainWallet as OrgWallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Withdrawing from the organization wallet (owner, 28 Nov): only by the account owner (the organization's first Super
 * Admin), only to their own My Wallet (a LIPR wallet-to-wallet transfer), and only after they enter a 6-digit code
 * emailed to them. Other Super Admins deposit and move money but cannot take it out. The code is tied to the amount and expires.
 * The free balance is taken first and given back when LIPR refuses the transfer, at once or in its callback.
 */
class OrganizationWithdrawal
{
    public const CODE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    /** Transactions method of both rows: the Super Admin's withdraw (organization side) and deposit (their My Wallet). */
    public const METHOD = 'org-wallet-to-my-wallet';

    private static function key(Organization $organization, User $user): string
    {
        return 'org-withdraw:'.$organization->id.':'.$user->id;
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public static function canWithdraw(?User $user, Organization $organization): bool
    {
        return $user && (int) $organization->owner_user_id === (int) $user->id;
    }

    private static function check(User $user, Organization $organization, float $amount): void
    {
        if (! self::canWithdraw($user, $organization)) {
            throw new RuntimeException('Only the account owner can withdraw organization money; it goes to their own My Wallet.', 403);
        }
        if (! filled($organization->lipr_wallet)) throw new RuntimeException(OrgWallet::MISSING, 422);
        if (! filled($user->lipr_wallet)) {
            throw new RuntimeException('Set up your own My Wallet first: organization money is withdrawn to it.', 422);
        }
        if ($user->lipr_wallet === $organization->lipr_wallet) {
            throw new RuntimeException('Your My Wallet is the organization wallet\'s account; ask support to separate them before withdrawing.', 422);
        }
        if (! filled($user->email)) throw new RuntimeException('Your account has no email to send the code to.', 422);
        $free = round((float) $organization->fresh()->wallet_balance, 2);
        if ($amount > $free) {
            throw new RuntimeException('Your organization wallet has KES '.number_format($free, 2).' free (money moved to programs cannot be withdrawn), which does not cover KES '.number_format($amount, 2).'.', 422);
        }
    }

    /** Email a code for this amount. Returns the masked email it went to. */
    public static function sendCode(User $user, Organization $organization, float $amount): string
    {
        self::check($user, $organization, $amount);
        $code = (string) random_int(100000, 999999);
        Cache::put(self::key($organization, $user), ['hash' => self::hash($code), 'amount' => $amount, 'attempts' => 0], now()->addMinutes(self::CODE_MINUTES));

        $sent = app(EmailService::class)->send('Your organization wallet withdrawal code', 'organization.withdraw_code', [
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'there',
            'organization' => $organization->name ?: $organization->display_name,
            'amount' => number_format($amount, 2),
            'code' => $code,
            'minutes' => self::CODE_MINUTES,
        ], $user->email);
        if (! $sent) {
            Cache::forget(self::key($organization, $user));
            throw new RuntimeException('The code email could not be sent. Try again.', 422);
        }

        return self::mask($user->email);
    }

    /** Check the code, then move the money to the Super Admin's My Wallet. Returns the LIPR reference. */
    public static function withdraw(User $user, Organization $organization, float $amount, string $code): ?string
    {
        if (! self::canWithdraw($user, $organization)) {
            throw new RuntimeException('Only the account owner can withdraw organization money; it goes to their own My Wallet.', 403);
        }
        $key = self::key($organization, $user);
        // One check at a time per person, so a code cannot be used twice by parallel requests
        $lock = Cache::lock($key.':lock', 30);
        if (! $lock->get()) throw new RuntimeException('A withdrawal is already being checked. Wait a moment.', 409);

        try {
            $pending = Cache::get($key);
            if (! $pending) throw new RuntimeException('The code has expired or was not asked for. Ask for a new code.', 422);
            if ((float) $pending['amount'] !== $amount) {
                throw new RuntimeException('The code was sent for KES '.number_format((float) $pending['amount'], 2).'. Ask for a new code to withdraw a different amount.', 422);
            }
            if (! hash_equals($pending['hash'], self::hash(trim($code)))) {
                $pending['attempts']++;
                if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                    Cache::forget($key);
                    throw new RuntimeException('Too many wrong codes. Ask for a new code.', 422);
                }
                Cache::put($key, $pending, now()->addMinutes(self::CODE_MINUTES));
                throw new RuntimeException('That code is not right. '.(self::MAX_ATTEMPTS - $pending['attempts']).' tries left.', 422);
            }
            Cache::forget($key);

            self::check($user, $organization, $amount);
            OrgWallet::adjust($organization, -$amount);
            try {
                $res = app(LiprW2W::class)->send((int) round($amount), $user->lipr_wallet, $organization->lipr_wallet, null, [
                    'result_url' => LiprCallback::url('/lipr-callback/transfer'),
                    'listing_id' => 'ow-'.$organization->id,
                    'narration' => 'Organization wallet withdrawal',
                ]);
            } catch (Throwable $e) {
                $res = ['success' => false, 'message' => $e->getMessage()];
            }
            if (! ($res['success'] ?? false)) {
                OrgWallet::adjust($organization, $amount);
                $message = $res['message'] ?? (is_array($res['errors'] ?? null) ? implode(' ', $res['errors']) : null);
                throw new RuntimeException('The transfer did not go through'.($message && is_string($message) ? ': '.$message : '.').' Nothing was taken.', 422);
            }

            $reference = $res['reference'] ?? null;
            // The organization's money out (organization_id set by Transactions), and the same money into this
            // person's own My Wallet (their own row)
            foreach ([['withdraw', 'debit'], ['deposit', 'credit']] as [$type, $direction]) {
                Transactions::create([
                    'user_id' => $user->id, 'recipient_id' => null, 'type' => $type, 'direction' => $direction,
                    'method' => self::METHOD, 'gross_amount' => $amount, 'fee_amount' => 0, 'net_amount' => $amount,
                    'reference_id' => $reference, 'status' => 'settled', 'created_by' => $user->id,
                ]);
            }

            return $reference;
        } finally {
            $lock->release();
        }
    }

    /** LIPR said the transfer failed after accepting it: the money goes back to the organization wallet (once). */
    public static function failed(?string $reference, ?string $listingId): bool
    {
        if (! $reference || ! preg_match('/^ow-(\d+)$/', (string) $listingId, $m)) return false;
        $organization = Organization::find((int) $m[1]);
        if (! $organization) return false;

        return DB::transaction(function () use ($reference, $organization) {
            $rows = Transactions::where('method', self::METHOD)->where('reference_id', $reference)->where('status', 'settled')->lockForUpdate()->get();
            $debit = $rows->firstWhere('direction', 'debit');
            if (! $debit) return false;
            Transactions::whereIn('id', $rows->pluck('id'))->update(['status' => 'failed']);
            OrgWallet::adjust($organization, (float) $debit->gross_amount);

            return true;
        });
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
