<?php
namespace App\Service\LiprMpesa;

use Illuminate\Support\Facades\Http;

class MpesaTransfer
{
    public function initiatePaybill(
        float $amount,
        string $payer_wallet,
        string $narration,
        int $receiver_paybil_acc,
        int $receiver_paybill,

    )
    {
        /* payment_type can be either M2B_MPESA_PAYBILL or M2B_MPESA_TILL */

        if($amount <= 0) {
            return [
                'success' => false,
                'errors' => ['Amount must be greater than zero.'],
            ];
        }

        $base_path = config('services.lipr.base_path');
        $url = $base_path . "/partners/v1/payments/disburse";

        $fields = [
            'payment' => [
                'wallet_account' => $payer_wallet,
                'amount' => $amount,
                'narration' => $narration,
                "callback_url" => "https://tujitume.com/api/lipr-callback",
                "payment_type" => "M2B_MPESA_PAYBILL", //$payment_type
                "paybill_account_number" => $receiver_paybil_acc, //tax_acc_number
                "paybill_number" => $receiver_paybill,
                //"till_number" => $request->till_number,
            ],
        ];

        return $this->sendRequest($fields);
    }

    public function initiateMobile(
        float $amount,
        string $payer_wallet,
        string $narration,
        int $receiver_acc_number,
    )
    {
        if($amount <= 0) {
            return [
                'success' => false,
                'errors' => ['Amount must be greater than zero.'],
            ];
        }

        $base_path = config('services.lipr.base_path');
        $url = $base_path . "/partners/v1/payments/disburse";

        $fields = [
            'payment' => [
                'wallet_account' => $payer_wallet,
                'customer_account_number' => $receiver_acc_number, //"254721601031", //,
                'amount' => $amount, //KES
                'narration' => $narration,
                "callback_url" => "https://tujitume.com/api/lipr-callback",
                "payment_type" => "M2C_MPESA"
            ],
        ];

        return $this->sendRequest($fields);
    }

    public function initiateTill(
        float $amount,
        string $payer_wallet,
        string $narration,
        int $receiver_till_number,

    )
    {
        /* payment_type can be either M2B_MPESA_PAYBILL or M2B_MPESA_TILL */

        if($amount <= 0) {
            return [
                'success' => false,
                'errors' => ['Amount must be greater than zero.'],
            ];
        }

        $base_path = config('services.lipr.base_path');
        $url = $base_path . "/partners/v1/payments/disburse";

        $fields = [
            'payment' => [
                'wallet_account' => $payer_wallet,
                'amount' => $amount,
                'narration' => $narration,
                "callback_url" => "https://tujitume.com/api/lipr-callback",
                "payment_type" => "M2B_MPESA_TILL", //$payment_type
                "till_number" => $receiver_till_number,
            ],
        ];

        return $this->sendRequest($fields);
    }

    # H E L P E R
    private function sendRequest(array $fields)
    {
        $liprAuth = new LiprAuthService();
        $token = $liprAuth->authorize();

        $base_path = config('services.lipr.base_path');
        $url = $base_path . "/partners/v1/payments/disburse";

        $response = Http::withToken($token)
            ->acceptJson()->timeout(30)->post($url, $fields);

        if (!$response->successful()) {
            return [
                'success' => false,
                'errors' => [
                    $response->json()['message'] ?? 'Transfer failed.'
                ],
            ];
        }

        return [
            'success' => true,
            'data' => $response->json(),
        ];
    }



}

