<?php
namespace App\Service\LiprMpesa;

use App\Models\Programs\Disbursement;

class LiprW2W
{
    /**
     * @param object|null $milestone the milestone this pays (supplier / applicant payouts); null for other payments
     * @param array $options result_url, listing_id (what LIPR echoes back to the callback) and narration, for payments
     *                       that are not milestone payouts, such as a reviewer's fee
     */
    /**
     * Where LIPR reports the result: the caller's own callback, else the program payout callback for a program milestone
     * (it releases the milestone and completes the disbursement), else the plain transfer callback (recorded only).
     */
    public static function resultUrl($milestone, array $options = []): string
    {
        return $options['result_url'] ?? LiprCallback::url(
            $milestone instanceof \App\Models\Programs\ProgramMilestone ? '/lipr-callback/transfer-to-supplier' : '/lipr-callback/transfer'
        );
    }

    public function send($amount, string $receiver_wallet, string $payer_wallet, $milestone = null, array $options = [])
    {
        // Some callers pass a label ('Unlocking business fee') where the milestone goes: it is the narration, not a milestone
        // (reading ->amount on it threw before any money moved)
        if (is_string($milestone)) {
            $options['narration'] ??= $milestone;
            $milestone = null;
        }

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

        if ($milestone && !($milestone instanceof \App\Models\Programs\ProgramMilestone)) {
            $milestone = null;
        }

        // A program milestone payout is recorded as a disbursement; other payments (a fee, a business milestone) are tracked by their caller
        $disbursement = $milestone ? Disbursement::create([
            'amount' => $milestone->amount,
            'milestone_id' => $milestone->id,
            // ISSUE-31: on the program's wallet, so the wallet report lists payouts to the applicant too
            'wallet_id' => $milestone->application?->program?->wallet?->id,
            'recipient_type' => 'business_owner',
            'payment_method' => 'mpesa_mobile',
            'currency' => 'KES',
            'status' => 'pending',
            'authorized_by' => $milestone->application->program_owner_id,
        ]) : null;

        $liprAuth = new LiprAuthService();
        $token = $liprAuth->authorize();

        $base_path = config('services.lipr.base_path');
        $url = $base_path . "/partners/v1/wallets/transfer";


        $fields = [
            "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            "resultUrl" => self::resultUrl($milestone, $options),
            "timeoutUrl" => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/timeout'),
            "metadata" => [ "listingId" => $options['listing_id'] ?? $milestone?->id ],

            "fromWallet" => $payer_wallet,
            "toWallet" => $receiver_wallet,
            "amount" => $amount,
            "narration" => $options['narration'] ?? 'Mobile Money Transfer',
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
            'reference' => $result['data']['referenceId'] ?? $result['referenceId'] ?? $fields['requestId'],
            'message' => $result['message'] ?? $result
        ];

    }


    public function sendReviewerPayment($amount, string $receiver_wallet, string $payer_wallet, $order)
    {
        if ($amount <= 0) {
            return [
                'success' => false,
                'errors' => ['Amount must be greater than zero.'],
            ];
        }

        if (!$receiver_wallet) {
            return [
                'success' => false,
                'errors' => ['Receiver wallet not found.'],
            ];
        }

        if (!$payer_wallet) {
            return [
                'success' => false,
                'errors' => ['Payer wallet not found.'],
            ];
        }

        $liprAuth = new LiprAuthService();
        $token = $liprAuth->authorize();

        $base_path = config('services.lipr.base_path');
        $url = $base_path . "/partners/v1/wallets/transfer";


        $fields = [
            "requestId" => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            "resultUrl" => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/reviewer-payment'),
            "timeoutUrl" => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/timeout'),
            "metadata" => ["listingId" => $order->id],

            "fromWallet" => $payer_wallet,
            "toWallet" => $receiver_wallet,
            "amount" => $amount,
            "narration" => 'Mobile Money Transfer',
        ];

        $fields_string = json_encode($fields);
        //open connection
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . $token,
            "Cache-Control: no-cache",
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $result = json_decode($result, true);

        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'message' => $err];
        }

        if (!isset($result['status']) || $result['status'] !== 200) {
            return [
                'success' => false,
                'message' => $result['error'] ?? $result['message'] ?? json_encode($result),
            ];
        }

        return   [
            'success' => true,
            'response' => $result,
            'message' => $result['message'] ?? $result
        ];
    }

}
