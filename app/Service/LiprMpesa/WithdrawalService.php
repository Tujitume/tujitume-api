<?php
namespace App\Service\LiprMpesa;

use App\Models\Auth\User;
use App\Models\Finance\LiprPayment;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\Balance\RetrieveBalanceService;
use App\Service\LiprMpesa\LiprAuthService;
use App\Service\Misc\TransactionRecord;
use App\Service\Notification\NotificationService;
use Illuminate\Support\Facades\Http;

class WithdrawalService
{
    public function __construct(
        private LiprPaymentService    $lipr,
        private RetrieveBalanceService $retrieveBalance,
        private BalanceService        $balance,
        private TransactionRecord    $transaction,
        private NotificationService   $notification,
        private CurrencyConverter     $converter,
    ) {}

    // ─── Initiate Methods ───────────────────────────────────────────────

    public function initiateToMobile(User $user, float $amountKes, string $phone): array
    {
        $this->validateWithdrawal($user, $amountKes);

        return $this->lipr->toMobile($user->lipr_wallet, $phone, $amountKes);
    }

    public function initiateToPaybill(User $user, float $amountKes, string $paybillNumber, string $paybillAccount): array
    {
        $this->validateWithdrawal($user, $amountKes);

        return $this->lipr->toPaybill(
            $user->lipr_wallet, $paybillNumber, $paybillAccount, $amountKes
        );
    }

    public function initiateToTill(User $user, float $amountKes, string $tillNumber): array
    {
        $this->validateWithdrawal($user, $amountKes);

        return $this->lipr->toTill($user->lipr_wallet, $tillNumber, $amountKes);
    }

    // ─── Status / Completion Methods ────────────────────────────────────

    public function processStatus(User $user, string $referenceId, string $method): array
    {
        $payment = LiprPayment::where('reference_id', $referenceId)
            ->lockForUpdate()
            ->first();

        // Not yet in DB - still pending
        if (!$payment) {
            return ['status' => 'pending', 'updated_at' => now()];
        }

        if ($payment->status === 'failed') {
            return [
                'status'     => 'failed',
                'message'    => 'Customer rejected payment or did not pay',
                'updated_at' => now(),
            ];
        }

        if ($payment->status === 'completed') {
            throw new \Exception('Payment already completed.', 409);
        }

        if ($payment->status !== 'processed') {
            throw new \Exception('Payment not ready for crediting.', 422);
        }

        $amountUsd = $payment->amount_usd;

        // Deduct balance
        $this->balance->updateBalanceMinus($user->id, $amountUsd, "lipr-{$method}");
        $payment->update(['status' => 'completed']);

        // Record transaction
        $this->transaction->create(
            $user->id, 'withdraw', "lipr-{$method}", $payment->amount, $referenceId
        );

        // Notify user
        $this->notifyWithdrawal($user, $amountUsd);

        return ['status' => 'processed', 'updated_at' => now()];
    }


    // ─── Validation ─────────────────────────────────────────────────────

    public function validateWithdrawal(User $user, float $amountKes): void
    {
        if (!$user->lipr_wallet) {
            throw new \Exception('Wallet does not exist, please create your lipr wallet first', 404);
        }

        $liprBalance = $this->retrieveBalance->lipr($user->id);

        if ($amountKes > $liprBalance) {
            throw new \Exception("{$amountKes} (KES) Insufficient balance to complete request", 422);
        }

        $amountUsd = round($this->converter->KesToUsd() * $amountKes, 2);

        if ($amountUsd > $user->balance->balance) {
            throw new \Exception("{$amountUsd} (\$) Insufficient balance to complete request", 422);
        }
    }

    // ─── Notifications ───────────────────────────────────────────────────

    private function notifyWithdrawal(User $user, float $amountUsd): void
    {
        $link = in_array($user->user_type_id, [2, 3]) ? 'overview/account' : 'account';
        $text = "Hi, your wallet was debited by USD \${$amountUsd} from withdraw.";

        $this->notification->create($user->id, $user->id, $text, $link, 'withdraw');
    }
}
