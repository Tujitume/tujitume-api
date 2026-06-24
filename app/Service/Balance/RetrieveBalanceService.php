<?php
namespace App\Service\Balance;

use App\Models\Auth\User;
use App\Service\LiprMpesa\LiprAuthService;

class RetrieveBalanceService
{
    public function __construct($client)
    {
        $this->Client = $client; // injected automatically
    }

    public function stripe(int $userId)
    {
        $user = User::find($userId);
        if($user->connect_id && $user->completed_onboarding)
        {
          $balanceA = $this->Client->balance->retrieve(null,
              ['stripe_account' => $user->connect_id])->available[0]->amount ?? 0;

          $balanceP= $this->Client->balance->retrieve(null,
              ['stripe_account'=>$user->connect_id])->pending[0]->amount ?? 0;

          $balanceAvailable = (float)($balanceA/100);
          //$balancePending = (float)($balanceP/100);
          return $balanceAvailable;
        }
        else
        {
            return false;
        }

    }

    public function lipr(int $userId)
    {
        $liprAuth = new LiprAuthService();
        $user = User::find($userId);
        $wallet_id = $user->lipr_wallet;

        if (!$user || !$user->lipr_wallet) {
            return [
                'success' => false,
                'message' => 'Wallet not found',
                'balance' => null,
            ];
        }

        $token = $liprAuth->authorize();

        $base_path = config('services.lipr.base_path');
        $endpoint = $base_path . "/partners/v1/wallets/";

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $endpoint . $wallet_id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer ".$token,
                "Cache-Control: no-cache",
            ),
        ));

        $response = curl_exec($curl);
        $response = json_decode($response, true);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err)
            return [
                'success' => false,
                'message' => $err,
                'balance' => null,
            ];

        if (!isset($response['success']) || !$response['success']) {
            return [
                'success' => false,
                'message' => $response['error'] ?? 'Lipr API error',
                'balance' => null,
            ];
        }

        return [
            'success' => true,
            'message' => null,
            'balance' => (float) $response['data']['wallet']['balance'],
        ];

    }

    public function getStripeAccount($seller)
    {
        return $this->Client->accounts->retrieve($seller->connect_id);
    }

}

