<?php

namespace App\Service\Business\Milestone;

use App\Models\Misc\Setting;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\TransactionRecord;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use RuntimeException;
use Stripe\StripeClient;

class MilestoneFundReleaseService
{
    protected EmailService        $emailService;
    protected NotificationService $notification;
    protected TransactionRecord   $transaction;
    protected BalanceService      $balance;
    protected LiprW2W             $liprW2W;
    protected StripeClient        $Client;
    protected float               $usdToKes;
    protected ?string             $tujitume_lipr_wallet;

    public function __construct(StripeClient $Client)
    {
        $this->Client               = $Client;
        $this->emailService         = new EmailService();
        $this->notification         = new NotificationService();
        $this->transaction          = new TransactionRecord();
        $this->liprW2W              = new LiprW2W();
        $this->balance              = new BalanceService();
        $this->usdToKes             = (float) app(CurrencyConverter::class)->UsdToKes();
        $this->tujitume_lipr_wallet = Setting::where('key', 'platform_lipr_wallet')->first()?->value ?? null;
    }

    /**
     * @param  int  $percentage  25 (close-out) or 75 (pre-completion)
     */
    public function release($milestone, int $percentage): FundReleaseResult
    {
        if( !in_array($percentage, [25,75]) ){
            return FundReleaseResult::failure('Invalid percentage.');
        }

        // Each part is paid once: 75% before the work, 25% at close-out
        if (($percentage === 75 && $milestone->fund_released_75) || ($percentage === 25 && $milestone->fund_released)) {
            return FundReleaseResult::failure('This part of the milestone\'s money has already been released.');
        }

        $owner          = $milestone->listing->owner;
        // Audit fees the investors' money already paid to a project manager come off this release (DECISION-9)
        $auditFeesUsd   = \App\Service\Audits\AuditFee::businessDeductionUsd((int) $milestone->id);
        $transferAmount = round(max(0, $milestone->amount * ($percentage / 100) - $auditFeesUsd), 2);
        // Paid out the way the investors paid in: M-Pesa (LIPR) investments are sent from the platform wallet by LIPR.
        // payout_method defaults to "stripe" and nothing sets it, so it used to try Stripe for every milestone.
        $paidByLipr = \App\Models\Business\AcceptedBids::where('ms_id', $milestone->id)->where('method', 'lipr')->exists();
        $method     = $paidByLipr ? 'lipr' : ($milestone->payout_method ?: 'stripe');

        try {
            $transfer = match ($method) {
                'stripe' => $this->dispatchStripe($owner, $transferAmount),
                'lipr'   => $this->dispatchLipr($owner, $transferAmount),
                default  => throw new RuntimeException("Unsupported payment method: {$method}."),
            };
        } catch (RuntimeException $e) {
            return FundReleaseResult::failure($e->getMessage());
        }

        // A Stripe transfer is an object with an id; LIPR answers an array (success + reference)
        $transferId = is_array($transfer)
            ? (!empty($transfer['success']) ? ($transfer['reference'] ?? 'lipr') : null)
            : ($transfer->id ?? null);
        if (!$transferId) {
            // LIPR's own reason (e.g. the platform wallet's balance) is kept and logged: it used to be dropped, so a
            // release that failed left no trace
            $reason = is_array($transfer) ? ($transfer['message'] ?? (is_array($transfer['errors'] ?? null) ? implode(' ', $transfer['errors']) : null)) : null;
            \Illuminate\Support\Facades\Log::warning('Milestone fund release failed', ['milestone' => $milestone->id, 'percentage' => $percentage, 'amount_kes' => $transferAmount, 'reason' => $reason]);

            return FundReleaseResult::failure('Transfer could not be completed'.($reason ? ': '.(is_string($reason) ? $reason : json_encode($reason)) : '').'. Please try again.');
        }

        if ($auditFeesUsd > 0) {
            \App\Service\Audits\AuditFee::markBusinessDeducted((int) $milestone->id);
        }

        $this->balance->updateBalance($owner->id, $transferAmount, $method);

        $this->transaction->create(
            $owner->id,
            'business_milestone',
            $method,
            $transferAmount,
            $transferId,
            $owner->id,
        );

        $this->notification->create(
            $owner->id,
            null,
            "Milestone funds of KSh ".number_format($transferAmount, 2)." ({$percentage}%) have been released to your account for milestone: {$milestone->title}",
            'dealroom',
            'milestone',
        );

        $this->emailService->send(
            'Milestone Funds Released',
            'milestone.funds_released_bo',
            [
                'release_type'    => $percentage === 75 ? 'pre' : 'mid',
                'boName'          => $milestone->listing->name,
                'milestoneTitle'  => $milestone->title,
                'amount'          => $milestone->amount,
                'released_amount' => $transferAmount,
                'dashboardUrl'    => \App\Service\Notification\RecipientUrl::url('dashboard/milestones', $owner),
            ],
            $owner->email,
        );

        return FundReleaseResult::success('Fund release success.');
    }

    private function dispatchStripe($owner, float $amount): object
    {
        if (!$owner->connect_id) {
            throw new RuntimeException('Business owner is not onboarded to Stripe.');
        }

        return $this->Client->transfers->create([
            'amount'      => (int) round($amount * 100),
            'currency'    => 'USD',
            'destination' => $owner->connect_id,
        ]);
    }

    private function dispatchLipr($owner, float $amount): array
    {
        if(!$owner->lipr_wallet){
            throw new RuntimeException('Business owner is not onboarded to Lipr.');
        }
        if (!$this->tujitume_lipr_wallet) {
            throw new RuntimeException('The platform wallet is not set up.');
        }

        // A business's money (milestones, bids) is in KSh, the currency investors paid it in: sent as it is
        return app(LiprW2W::class)->send(
            (int) round($amount),
            $owner->lipr_wallet,
            $this->tujitume_lipr_wallet,
            null,
            ['narration' => 'Milestone fund release'],
        );
    }
}
