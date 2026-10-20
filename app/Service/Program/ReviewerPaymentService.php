<?php

namespace App\Service\Program;

use App\Models\Programs\Rounds\ProgramRound;
use App\Models\ReviewerOrder;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewerPaymentService
{
    public function __construct(
        private LiprW2W $liprW2W,
        private ProgramNotificationService $notifications
    ) {}

    /** Pay every approved, positive-fee reviewer order when its round is finalized. */
    public function payApprovedRoundOrders(ProgramRound $round): array
    {
        $result = ['started' => 0, 'sent' => 0, 'problems' => []];
        $orders = ReviewerOrder::with('reviewer')
            ->where('round_id', $round->id)
            ->where('order_type', 'round_review')
            ->where('work_status', 'approved')
            ->where('fee_amount', '>', 0)
            ->whereIn('payment_status', ['unpaid', 'failed'])
            ->get();

        foreach ($orders as $order) {
            $payment = $this->pay($order);
            if (! $payment['ok']) {
                $result['problems'][] = $payment['message'];
                continue;
            }

            $result['started']++;
            $result['sent']++;
            $this->notifications->send('reviewer.payment_initiated', [$order->reviewer], [
                'program_title' => $round->program->program_title,
                'round_name' => $round->round_name,
                'fee' => $order->fee_amount,
                'order_id' => $order->id,
            ]);
        }

        return $result;
    }

    /** Start one approved round payment through the original reviewer transfer endpoint. */
    public function pay(ReviewerOrder $order): array
    {
        try {
            $payment = DB::transaction(function () use ($order) {
                $lockedOrder = ReviewerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $reviewer = $lockedOrder->reviewer;
                $program = $lockedOrder->program()->with('owner')->firstOrFail();
                $owner = $program->owner;
                $wallet = $program->wallet()->lockForUpdate()->first();
                $amount = round((float) $lockedOrder->fee_amount, 2);
                $name = trim(($reviewer?->first_name ?? '') . ' ' . ($reviewer?->last_name ?? '')) ?: 'The reviewer';

                if ($lockedOrder->payment_status === 'completed') {
                    return ['error' => "{$name} has already been paid."];
                }
                if ($lockedOrder->payment_status === 'pending') {
                    return ['error' => "{$name}'s payment is already being processed."];
                }
                if ($lockedOrder->order_type !== 'round_review' || $lockedOrder->work_status !== 'approved') {
                    return ['error' => "{$name}'s work is not approved for round payment."];
                }
                if ($amount <= 0) {
                    return ['error' => "{$name} has no fee to pay."];
                }
                if (! $reviewer?->lipr_wallet) {
                    return ['error' => "{$name} has no LIPR wallet set up yet, so they cannot be paid. Ask them to set one up."];
                }
                if (! $owner?->lipr_wallet) {
                    return ['error' => 'The program owner has no LIPR wallet linked, so this reviewer cannot be paid yet.'];
                }
                if (! $wallet || (float) $wallet->balance < $amount) {
                    return ['error' => 'The program wallet balance is too low to pay ' . $name . '. Top it up and try again.'];
                }

                $lockedOrder->update(['payment_status' => 'pending']);

                return [
                    'order' => $lockedOrder,
                    'amount' => $amount,
                    'reviewer_wallet' => trim((string) $reviewer->lipr_wallet),
                    'owner_wallet' => trim((string) $owner->lipr_wallet),
                ];
            });

            if (isset($payment['error'])) {
                return ['ok' => false, 'message' => $payment['error']];
            }

            $transfer = $this->liprW2W->sendReviewerPayment(
                $payment['amount'],
                $payment['reviewer_wallet'],
                $payment['owner_wallet'],
                $payment['order']
            );

            if (! ($transfer['success'] ?? false)) {
                $payment['order']->update(['payment_status' => 'failed']);
                $reason = $transfer['message'] ?? implode(' ', $transfer['errors'] ?? []) ?: 'LIPR did not accept the transfer';
                Log::warning('Reviewer LIPR payment was refused', ['order_id' => $order->id, 'reason' => $reason]);

                return ['ok' => false, 'message' => 'Something went wrong while sending this payment. Please try again.'];
            }

            return ['ok' => true, 'message' => 'Payment initiated.'];
        } catch (\Throwable $e) {
            if (isset($payment['order'])) {
                $payment['order']->update(['payment_status' => 'failed']);
            }
            ErrorLogService::report($e, ['order_id' => $order->id]);

            return ['ok' => false, 'message' => 'The payment could not be started. Please try again.'];
        }
    }
}
