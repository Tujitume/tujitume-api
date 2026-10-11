<?php

namespace App\Service\Program;

use App\Models\Misc\Setting;
use App\Models\Programs\ProgramWallet;
use App\Models\ReviewerOrder;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pays a reviewer from the program wallet, over LIPR: a wallet-to-wallet transfer from the program's own LIPR wallet
 * (where its deposits are held) to the reviewer's own LIPR wallet. The money is held out of the program
 * wallet when the transfer is sent, and either settled or given back when LIPR reports the result
 * (see MpesaCallbackController::callbackForReviewerPaymentLeg2).
 */
class ReviewerPaymentService
{
    /**
     * Start paying one order.
     * @return array{ok: bool, message: string}
     */
    public function pay(ReviewerOrder $order): array
    {
        try {
            $held = null;

            $problem = DB::transaction(function () use ($order, &$held) {
                $order = ReviewerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $reviewer = $order->reviewer;
                $name = trim(($reviewer?->first_name ?? '') . ' ' . ($reviewer?->last_name ?? '')) ?: 'The reviewer';
                $amount = round((float) $order->fee_usd, 2);

                if ($order->payment_status === 'completed') {
                    return "{$name} has already been paid.";
                }
                if ($order->payment_status === 'leg1_processing') {
                    return "{$name}'s payment is already on its way.";
                }
                if ($amount <= 0) {
                    return "{$name} has no fee to pay.";
                }
                if (! $reviewer?->lipr_wallet) {
                    return "{$name} has no LIPR wallet set up yet, so they cannot be paid. Ask them to set one up.";
                }

                $wallet = ProgramWallet::where('program_id', $order->program_id)->lockForUpdate()->first();
                if (! $wallet || (float) $wallet->balance < $amount) {
                    return 'The program wallet balance is too low to pay ' . $name . '. Top it up and try again.';
                }

                // Program deposits land in the program's own LIPR wallet, so that is where the money is sent from
                // (the platform wallet only if this program has none of its own)
                $payerWallet = trim((string) ($wallet->lipr_wallet ?: Setting::where('key', 'platform_lipr_wallet')->value('value')));
                if (! $payerWallet) {
                    return 'This program has no LIPR wallet linked, so nobody can be paid from it yet.';
                }

                // Hold the money out of the wallet while the transfer is in flight
                $wallet->balance = (float) $wallet->balance - $amount;
                $wallet->total_reserved = (float) $wallet->total_reserved + $amount;
                $wallet->save();

                $order->update([
                    'work_status' => 'approved',
                    'approved_at' => $order->approved_at ?? now(),
                    'payment_status' => 'leg1_processing',
                ]);

                $held = ['order' => $order, 'amount' => $amount, 'wallet' => trim((string) $reviewer->lipr_wallet), 'payer' => $payerWallet,
                    'payer_source' => $wallet->lipr_wallet ? 'the program wallet' : 'the platform wallet setting'];

                return null;
            });

            if ($problem !== null) {
                return ['ok' => false, 'message' => $problem];
            }

            $transfer = (new LiprW2W())->send(
                $held['amount'],
                $held['wallet'],
                (string) $held['payer'],
                null,
                [
                    'result_url' => rtrim((string) config('app.api_url'), '/') . '/lipr-callback/reviewer-payment-leg2',
                    'listing_id' => $held['order']->id,
                    'narration' => 'Reviewer payment',
                ]
            );

            if (! ($transfer['success'] ?? false)) {
                $this->release($held['order']->id, $held['amount']);

                $reason = $transfer['message'] ?? (implode(' ', $transfer['errors'] ?? []) ?: 'LIPR did not accept it');
                // Show which wallets were used (last 4 characters only), so a wrong wallet is easy to spot
                $tail = fn ($w) => '…' . substr((string) $w, -4);
                Log::warning('Reviewer LIPR payment was refused', [
                    'order_id' => $held['order']->id, 'reason' => $reason,
                    'from' => $tail($held['payer']), 'from_source' => $held['payer_source'], 'to' => $tail($held['wallet']),
                ]);

                return ['ok' => false, 'message' => "The transfer could not be sent: {$reason}. It was sent from {$held['payer_source']} ({$tail($held['payer'])}) to the reviewer's wallet ({$tail($held['wallet'])}). Nothing was taken from the program wallet."];
            }

            $held['order']->update(['leg2_reference' => $transfer['reference'] ?? null]);

            return ['ok' => true, 'message' => 'Payment sent. The reviewer is told when it lands.'];
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['order_id' => $order->id]);

            return ['ok' => false, 'message' => 'The payment could not be started. Please try again.'];
        }
    }

    /** Give a held amount back to the program wallet and put the order back to "started" so it can be retried. */
    public function release(int $orderId, ?float $amount = null, string $status = 'pending'): void
    {
        DB::transaction(function () use ($orderId, $amount, $status) {
            $order = ReviewerOrder::whereKey($orderId)->lockForUpdate()->first();
            if (! $order || $order->payment_status === 'completed') {
                return;
            }

            $amount = $amount ?? round((float) $order->fee_usd, 2);
            $wallet = ProgramWallet::where('program_id', $order->program_id)->lockForUpdate()->first();
            if ($wallet) {
                $wallet->balance = (float) $wallet->balance + $amount;
                $wallet->total_reserved = max(0, (float) $wallet->total_reserved - $amount);
                $wallet->save();
            }

            $order->update(['payment_status' => $status]);
        });
    }

    /** The transfer landed: the held amount is now spent. */
    public function settle(ReviewerOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $amount = round((float) $order->fee_usd, 2);
            $wallet = ProgramWallet::where('program_id', $order->program_id)->lockForUpdate()->first();
            if ($wallet) {
                $wallet->total_reserved = max(0, (float) $wallet->total_reserved - $amount);
                $wallet->total_disbursed = (float) $wallet->total_disbursed + $amount;
                $wallet->save();
            }
        });
    }
}
