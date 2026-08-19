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
        $this->usdToKes             = (new CurrencyConverter())->UsdToKes();
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

        $owner          = $milestone->listing->owner;
        $transferAmount = round($milestone->amount * ($percentage / 100), 2);
        $method         = $milestone->payout_method;

        try {
            $transfer = match ($method) {
                'stripe' => $this->dispatchStripe($owner, $transferAmount),
                'lipr'   => $this->dispatchLipr($owner, $transferAmount),
                default  => throw new RuntimeException("Unsupported payment method: {$method}."),
            };
        } catch (RuntimeException $e) {
            return FundReleaseResult::failure($e->getMessage());
        }

        if (!$transfer?->id) {
            return FundReleaseResult::failure('Transfer could not be completed. Please try again.');
        }

        $this->balance->updateBalance($owner->id, $transferAmount, $method);

        $this->transaction->create(
            $owner->id,
            'business_milestone',
            $method,
            $transferAmount,
            $transfer->id,
            $owner->id,
        );

        $this->notification->create(
            $owner->id,
            null,
            "Milestone funds of \${$transferAmount} ({$percentage}%) have been released to your account for milestone: {$milestone->title}",
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
                'dashboardUrl'    => 'https://beta.tujitume.com/dashboard/milestones',
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

    private function dispatchLipr($owner, float $amount): object
    {
        if(!$owner->lipr_wallet){
            throw new RuntimeException('Business owner is not onboarded to Lipr.');
        }

        return $this->liprW2W->send(
            round($this->usdToKes * $amount, 2),
            $owner->lipr_wallet,
            $this->tujitume_lipr_wallet,
            'Milestone fund release',
        );
    }
}
