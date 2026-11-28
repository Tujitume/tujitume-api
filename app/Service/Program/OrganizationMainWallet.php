<?php

namespace App\Service\Program;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramWallet;
use App\Models\ReviewerOrder;
use App\Service\Organization\OrganizationPermission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The organization wallet (DECISION-5 / DECISION-6, owner 27 Nov).
 *  - An organization has its OWN LIPR account (organizations.lipr_wallet, needing the organization's KYC), never the owner's
 *    personal wallet. Its money is tracked in KES in organizations.wallet_balance.
 *  - Program wallets are escrow on paper: moving money to a program takes it out of the organization balance (so set-aside
 *    money cannot be withdrawn), and every program payout is SENT from the organization's LIPR account while the program
 *    wallet balance is lowered.
 *  - Unused (uncommitted) program money can be moved back to the organization wallet; never straight to M-Pesa.
 *  - Only the organization's Super Admins (and its owner) may deposit, move, move back or withdraw; others read.
 */
class OrganizationMainWallet
{
    public const MISSING = "Your organization wallet is not set up yet, so program payments cannot be sent. A Super Admin can set it up under Organization wallet.";

    public static function organizationOf(Program $program): ?Organization
    {
        $owner = $program->owner ?? User::find($program->user_id);
        $owner?->loadMissing('organization');

        return $owner?->organization ?? ($program->organization_id ?? null ? Organization::find($program->organization_id) : null);
    }

    /** The organization's own LIPR account, or null when it has none (no fallback to anyone's personal wallet). */
    public static function account(Program $program): ?string
    {
        $account = self::organizationOf($program)?->lipr_wallet;

        return filled($account) ? trim((string) $account) : null;
    }

    public static function canManage(?User $user, Organization $organization): bool
    {
        if (! $user) return false;
        if ((int) $organization->owner_user_id === (int) $user->id) return true;
        if ((int) $user->organization_id !== (int) $organization->id) return false;

        return in_array(OrganizationPermission::roleOf($user), ['owner', 'super_admin'], true);
    }

    public static function canView(?User $user, Organization $organization): bool
    {
        return $user && ((int) $user->organization_id === (int) $organization->id || (int) $organization->owner_user_id === (int) $user->id);
    }

    /** The organization's program wallets. */
    public static function programWallets(Organization $organization)
    {
        $members = User::where('organization_id', $organization->id)->pluck('id')->push($organization->owner_user_id)->unique();

        return ProgramWallet::whereIn('program_id', Program::whereIn('user_id', $members)->select('id'))->with('program:id,program_title')->get();
    }

    /** What the organization holds: free in its wallet, set aside in programs, and the total. */
    public static function summary(Organization $organization): array
    {
        $wallets = self::programWallets($organization);
        $available = round((float) $organization->wallet_balance, 2);
        $setAside = round((float) $wallets->sum('balance'), 2);

        return [
            'organization_id' => $organization->id,
            // The registered name, as the top bar shows it (a money account is named by the organization's legal name)
            'name' => $organization->name ?: $organization->display_name,
            'lipr_wallet' => $organization->lipr_wallet,
            'currency' => 'KES',
            'available' => $available,
            'set_aside' => $setAside,
            'total' => round($available + $setAside, 2),
            'programs' => $wallets->map(fn (ProgramWallet $w) => [
                'wallet_id' => $w->id, 'program_id' => $w->program_id, 'program_title' => $w->program?->program_title,
                'balance' => (float) $w->balance, 'committed' => self::committed($w), 'free' => self::free($w),
            ])->values()->all(),
        ];
    }

    /**
     * Program money that is already promised: approved-but-unpaid Business Coach / site visit fees, and milestones approved
     * for release but not yet paid (amount + the fee the program pays on top).
     */
    public static function committed(ProgramWallet $wallet): float
    {
        $fees = ReviewerOrder::where('program_id', $wallet->program_id)->where('work_status', 'approved')
            ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])->sum('fee_amount');

        $releases = ProgramMilestone::whereHas('application', fn ($q) => $q->where('program_id', $wallet->program_id))
            ->where('is_template', false)->whereIn('fund_release_status', ['approved', 'processing'])->sum('amount');
        $releaseFees = $releases - app(\App\Service\Balance\CheckoutAmountCalculator::class)->mpesaGC((float) $releases, 'mpesa');

        return round((float) $fees + (float) $releases + max(0, (float) $releaseFees), 2);
    }

    public static function free(ProgramWallet $wallet): float
    {
        return round(max(0, (float) $wallet->balance - self::committed($wallet)), 2);
    }

    /** Move money from the organization wallet into a program wallet (bookkeeping: no money moves at LIPR). */
    public static function moveToProgram(Organization $organization, ProgramWallet $wallet, float $amount): void
    {
        DB::transaction(function () use ($organization, $wallet, $amount) {
            $org = Organization::whereKey($organization->id)->lockForUpdate()->first();
            if ((float) $org->wallet_balance < $amount) {
                throw new RuntimeException('Your organization wallet has KES ' . number_format((float) $org->wallet_balance, 2)
                    . ' free, which does not cover moving KES ' . number_format($amount, 2) . ' to this program. Deposit to the organization wallet first.', 422);
            }
            $org->forceFill(['wallet_balance' => (float) $org->wallet_balance - $amount])->save();
            $w = ProgramWallet::whereKey($wallet->id)->lockForUpdate()->first();
            $w->balance = (float) $w->balance + $amount;
            $w->total_deposited = (float) $w->total_deposited + $amount;
            $w->status = 'active';
            $w->save();
        });
    }

    /** Move unused program money back to the organization wallet: never more than what is free (not committed). */
    public static function moveBack(Organization $organization, ProgramWallet $wallet, float $amount): void
    {
        DB::transaction(function () use ($organization, $wallet, $amount) {
            $w = ProgramWallet::whereKey($wallet->id)->lockForUpdate()->first();
            $free = self::free($w);
            if ($amount > $free) {
                throw new RuntimeException('Only KES ' . number_format($free, 2) . ' of this program wallet is free; the rest is promised to approved payments (coach and site visit fees, approved releases).', 422);
            }
            $w->balance = (float) $w->balance - $amount;
            $w->total_deposited = max(0, (float) $w->total_deposited - $amount);
            $w->save();
            $org = Organization::whereKey($organization->id)->lockForUpdate()->first();
            $org->forceFill(['wallet_balance' => (float) $org->wallet_balance + $amount])->save();
        });
    }

    /** Add to / take from the organization's free balance (deposits, withdrawals), refusing to go below zero. */
    public static function adjust(Organization $organization, float $delta): void
    {
        DB::transaction(function () use ($organization, $delta) {
            $org = Organization::whereKey($organization->id)->lockForUpdate()->first();
            $next = (float) $org->wallet_balance + $delta;
            if ($next < 0) {
                throw new RuntimeException('Your organization wallet has KES ' . number_format((float) $org->wallet_balance, 2)
                    . ' free (money moved to programs cannot be withdrawn), which does not cover KES ' . number_format(-$delta, 2) . '.', 422);
            }
            $org->forceFill(['wallet_balance' => $next])->save();
        });
    }
}
