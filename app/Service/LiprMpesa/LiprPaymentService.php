<?php
namespace App\Service\LiprMpesa;

use App\Service\LiprMpesa\LiprAuthService;
use Illuminate\Support\Facades\Http;

class LiprPaymentService
{
    private string $basePath;
    private string $callbackUrl;

    public function __construct(
        private LiprAuthService $liprAuth,
    ) {
        $this->basePath    = config('services.lipr.base_path');
        $this->callbackUrl = 'https://tujitume.com/api/lipr-callback';
    }

    // ─── Payment Type Methods ───────────────────────────────────────────

    public function toMobile($milestoneId, string $walletAccount, string $customerAccNumber, float $amountKes): array
    {
        $fields = [
            "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            "resultUrl" => "https://tujitume.com/api/lipr-callback-grant-supplier",
            "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
            "metadata" => [ "listingId" => $milestoneId ],

            "wallet" => $walletAccount,
            "narration" => 'Mobile Money Transfer',
            "customerAccountNumber" => $this->sanitizePhone($customerAccNumber),
            "recipients" => [
                [
                    "amount" => $amountKes, "account" => $this->sanitizePhone($customerAccNumber) // can be multiple with [],[]
                ]
            ]
        ];

        return $this->disburse('to_mobile', $fields);
    }

    public function toPaybill(string $walletAccount, string $paybillNumber, string $paybillAccount, float $amountKes): array
    {
        $fields = [
            "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            "resultUrl" => "https://tujitume.com/api/lipr-callback",
            "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
            "wallet" => $walletAccount,
            "narration" => 'Mobile Money Transfer',
            "customerAccountNumber" => $this->sanitizePhone($phone),
            "recipients" => [
                [
                    "amount" => $amountKes,
                    "account" => $this->sanitizePhone($phone),
                    "businessNumber" => "888880"
                ]
            ]
        ];
        return $this->disburse('to_paybill', $fields);
    }

    public function toTill(string $walletAccount, string $tillNumber, float $amountKes): array
    {
        $fields = [
            "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            "resultUrl" => "https://tujitume.com/api/lipr-callback",
            "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",

            "wallet" => $walletAccount,
            "narration" => 'Mobile Money Transfer',
            "recipients" => [
                [
                    "amount" => $amountKes, "account" => $tillNumber // till / Buy Goods number
                ]
            ]
        ];

        return $this->disburse('to_till', $fields);
    }

    public function toBankTransfer(string $walletAccount, string $bankAccount, string $bankName, float $amountKes, string $swiftCode = null): array
    {
        $fields = [
            "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            "resultUrl" => "https://tujitume.com/api/lipr-callback",
            "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
            "wallet" => $walletAccount, //2547XXXXXXXX
            "narration" => 'Mobile Money Transfer',
            "recipients" => [
                [
                    "amount" => $amountKes,
                    "account" => "1234567890", // bank account number
                    "bankId" => 12, // Lipr bank ID
                    "bankBranch" => "001", // branch code (if required)
                    "accountName" => "Jane"
                ]
            ]
        ];

        return $this->disburse('to_till',$fields);
    }

    public function toLiprWallet(string $walletAccount, string $recipientWallet, float $amountKes): array
    {
        return $this->disburse('to_wallet',$walletAccount, [
            'amount'            => $amountKes,
            'narration'         => 'Wallet Transfer',
            'payment_type'      => 'M2M_LIPR',
            'recipient_wallet'  => $recipientWallet,
        ]);
    }

    // ─── Core Disburse ──────────────────────────────────────────────────

    public function disburse(string $payment_type, array $paymentFields): array
    {
        $token = $this->liprAuth->authorize();

        if($payment_type === 'to_mobile') {
            $url   = $this->basePath . '/partners/v1/payments/mobile-money/send';
        }
        elseif($payment_type === 'to_paybill') {
            $url   = $this->basePath . '/partners/v1/payments/mobile-money/paybill';
        }
        elseif($payment_type === 'to_till') {
            $url   = $this->basePath . '/partners/v1/payments/mobile-money/till';
        }

        elseif($payment_type === 'to_bank') {
            $url   = $this->basePath . '/partners/v1/payments/bank-transfer/send';
        }
        else {
            throw new \Exception('Invalid payment type specified');
        }


        $fields =  $paymentFields;

        $response = $this->post($url, $fields, $token);
        $decoded  = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid response from LIPR API');
        }

        return $decoded;
    }


    // ─── Response Helpers ───────────────────────────────────────────────

    public function isSuccess(array $response): bool
    {
        return (
            ($response['success'] ?? false) === true
            ||
            ($response['data'][0]['success'] ?? false) === true
            ||
            ($response['data']['success'] ?? false) === true
        );
    }

    public function getError(array $response): string
    {
        return $response['error'] ?? $response['message'] ?? $response['errors'] ?? json_encode($response) ?? 'Unknown error from payment provider';
    }

    public function getStatusCode(array $response): int
    {
        return $response['status_code'] ?? $response['status']?? 500;
    }

    public function getReferenceId(array $response): ?string
    {
        return $response['data'][0]['reference'] ?? $response['data']['reference'] ?? null;
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    private function post(string $url, array $fields, string $token): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Cache-Control: no-cache",
            "Content-Type: application/json",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('LIPR API connection failed: ' . curl_error($ch));
        }

        curl_close($ch);

        return $result;
    }
}

