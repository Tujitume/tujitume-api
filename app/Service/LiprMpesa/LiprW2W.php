<?php
namespace App\Service\LiprMpesa;

use App\Models\Programs\Disbursement;

class LiprW2W
{
    public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone)
    {
        if($amount <= 0) {
            return [
                'success' => false,
                'errors' => ['Amount must be greater than zero.'],
            ];
        }

        if(!$receiver_wallet) {
            return [
                'success' => false,
                'errors' => ['Receiver wallet not found.'],
            ];
        }

        if(!$payer_wallet) {
            return [
                'success' => false,
                'errors' => ['Payer wallet not found.'],
            ];
        }

        $disbursement = Disbursement::create([
            'amount' => $milestone->amount,
            'milestone_id' => $milestone->id,
            'recipient_type' => 'business_owner',
            'payment_method' => 'mpesa_mobile',
            'currency' => 'KES',
            'status' => 'pending',
            'authorized_by' => $milestone->application->program_owner_id,
        ]);

        $liprAuth = new LiprAuthService();
        $token = $liprAuth->authorize();

        $base_path = config('services.lipr.base_path');
        $url = $base_path . "/partners/v1/wallets/transfer";


        $fields = [
            "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            "resultUrl" => "https://tujitume.com/api/lipr-callback-grant-supplier",
            "timeoutUrl" => "https://tujitume.com/api/lipr-callback/timeout",
            "metadata" => [ "listingId" => $milestone->id ],

            "fromWallet" => $payer_wallet,
            "toWallet" => $receiver_wallet,
            "amount" => $amount,
            "narration" => 'Mobile Money Transfer',
        ];

        $fields_string = json_encode($fields);
        //open connection
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer ".$token,
            "Cache-Control: no-cache",
            'Content-Type: application/json'
        ));
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $result = json_decode($result, true);

        $err = curl_error($ch);
        curl_close($ch);

        if ($err){
            return [ 'success' => false, 'message' => $err ];
        }

        if (!isset($result['status']) || $result['status'] !== 200) {
            return [
                'success' => false,
                'message' => $result['error'] ?? $result['message'] ?? json_encode($result),
                'disbursement' => $disbursement,
            ];
        }

        return   [
            'success' => true,
            'response' => $result,
            'disbursement' => $disbursement,
            'message' => $result['message'] ?? $result
        ];

    }

}

