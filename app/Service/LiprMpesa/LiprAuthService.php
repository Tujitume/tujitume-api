<?php
namespace App\Service\LiprMpesa;

use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\Http;

class LiprAuthService
{
    public function authorize()
    {
        try {

            $base_path = config('services.lipr.base_path');
            $endpoint = $base_path . "/partners/v1/auth/token";

            $payload = [
                'apiKey' => config('services.lipr.api_key'),
                'apiSecret' => config('services.lipr.api_secret'),
            ];

            $response = Http::timeout(15)
                ->acceptJson()
                ->withHeaders([
                    'Subscription-Key' => config('services.lipr.subscription_key'),
                ])->post($endpoint, $payload);

            if (!$response->successful()) {
                throw new \Exception('Failed to authorize with LIPR API.');
            }

            $responseData = $response->json();

            if (!isset($responseData['data']['accessToken'])) {
                throw new \Exception('Authorization token missing from LIPR response.');
            }

            return $responseData['data']['accessToken'];

        } catch (\Exception $e) {

            ErrorLogService::report($e, ['context' => 'LIPR Authorization',]);

            return response()->json([
                'status' => 400,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function transactions($transactionId)
    {
        try {

            $token = $this->authorize();

            $base_path = config('services.lipr.base_path');

            $endpoint = $base_path . "partners/v1/app_transactions/{$transactionId}";

            $response = Http::timeout(30)
                ->withToken($token)->acceptJson()->get($endpoint);

            if (!$response->successful()) {
                throw new \Exception('Failed to fetch transaction.');
            }

            $responseData = $response->json();

            return response()->json(['data' => $responseData], 200);

       } catch (\Exception $e) {
            ErrorLogService::report($e, ['context' => 'LIPR Transactions']);

            return response()->json(['status' => 400, 'message' => $e->getMessage()], 400);
        }
    }

}
