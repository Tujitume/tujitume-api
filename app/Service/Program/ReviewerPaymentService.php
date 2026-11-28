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

    /** Start one approved reviewer-order payment through the existing LIPR transfer callback. */
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
                if (!in_array($lockedOrder->order_type, ['round_review', 'site_visit'], true)
                    || $lockedOrder->work_status !== 'approved') {
                    return ['error' => "{$name}'s work is not approved for payment."];
                }
                if ($lockedOrder->order_type === 'site_visit'
                    && (!$lockedOrder->siteVisit || (int) $lockedOrder->siteVisit->reviewer_id !== (int) $lockedOrder->reviewer_id)) {
                    return ['error' => 'This site visit order is not linked to the assigned Business Coach.'];
                }
                if ($amount <= 0) {
                    return ['error' => "{$name} has no fee to pay."];
                }
                if ($lockedOrder->order_type === 'round_review') {
                    $requiredApplications = $lockedOrder->minimumPaidApplications();
                    $completedApplications = $lockedOrder->applicationAssignments()
                        ->where('status', 'completed')
                        ->count();
                    if ($requiredApplications < 1 || $completedApplications < $requiredApplications) {
                        return [
                            'error' => "{$name} has not completed the minimum number of applications required for payment. {$completedApplications} completed, {$requiredApplications} required."
                        ];
                    }
                }
                if (! $reviewer?->lipr_wallet) {
                    // DECISION-2: the coach reviewed without payouts; now they are reminded to set them up
                    return ['error' => "{$name} has no LIPR wallet set up yet, so they cannot be paid. We have reminded them to set one up.", 'remind' => $reviewer];
                }
                // DECISION-4/5 (owner, 27 Nov): charged to the program wallet, sent from the organization's main LIPR account
                $mainAccount = \App\Service\Program\OrganizationMainWallet::account($program);
                if (! $mainAccount) {
                    return ['error' => \App\Service\Program\OrganizationMainWallet::MISSING];
                }
                if (! $wallet) {
                    return ['error' => 'This program has no wallet yet, so ' . $name . ' cannot be paid from it.'];
                }
                if ((float) $wallet->balance < $amount) {
                    return ['error' => 'The program wallet balance (KES ' . number_format((float) $wallet->balance, 2) . ') is too low to pay ' . $name . ' KES ' . number_format($amount, 2) . '. Top it up and try again.'];
                }

                $lockedOrder->update(['payment_status' => 'pending']);

                return [
                    'order' => $lockedOrder,
                    'amount' => $amount,
                    'reviewer_wallet' => trim((string) $reviewer->lipr_wallet),
                    'owner_wallet' => $mainAccount, // the payer: the organization's main account (DECISION-5)
                ];
            });

            if (isset($payment['error'])) {
                if (! empty($payment['remind'])) {
                    try {
                        (new \App\Service\Notification\ProgramNotificationService())->send('reviewer.payout_setup_needed', [$payment['remind']], [
                            'program_title' => $order->round?->program?->program_title ?? $order->program?->program_title ?? null,
                            'program_id' => $order->round?->program_id ?? $order->program_id ?? null,
                            'email_title' => 'Set up payouts to be paid',
                            'email_message' => 'Your payment for this program is ready, but you have no M-Pesa (LIPR) wallet set up. Open Settings and set up payouts so the program can pay you.',
                            'action_label' => 'Set up payouts',
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Payout reminder failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    }
                }
                return ['ok' => false, 'message' => $payment['error']];
            }

            // Actual lipr transfer
            $transfer = $this->liprW2W->sendReviewerPayment(
                $payment['amount'],
                $payment['reviewer_wallet'],
                $payment['owner_wallet'],
                $payment['order']
            );

            LOG::info('Reviewer LIPR payment transfer result', ['order_id' => $order->id, 'transfer' => $transfer]);

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
