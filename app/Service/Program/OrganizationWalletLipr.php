<?php

namespace App\Service\Program;

use App\Models\Organizations\Organization;
use App\Service\LiprMpesa\LiprAuthService;

/** The LIPR calls of the organization wallet: create its account, and ask M-Pesa to pay into it (STK). One place to stub. */
class OrganizationWalletLipr
{
    public function __construct(private LiprAuthService $auth) {}

    /** @return array{success: bool, account?: string, message?: string} */
    public function createAccount(Organization $organization): array
    {
        $response = $this->post('/partners/v1/wallets', [
            'parentWalletAccount' => 'tujitume',
            'walletName' => $organization->display_name ?: $organization->name,
            'walletDescription' => 'Tujitume organization wallet',
        ]);
        if (($response['status'] ?? null) !== 200 || empty($response['data']['account'])) {
            return ['success' => false, 'message' => $this->plain($response)];
        }

        return ['success' => true, 'account' => (string) $response['data']['account']];
    }

    /** @return array{success: bool, reference?: string, message?: string} */
    public function stkDeposit(Organization $organization, float $amount, string $phone): array
    {
        $response = $this->post('/partners/v1/payments/mobile-money/stk', [
            'requestId' => 'stk-' . now()->format('YmdHis') . '-' . uniqid(),
            'resultUrl' => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback'),
            'timeoutUrl' => \App\Service\LiprMpesa\LiprCallback::url('/lipr-callback/timeout'),
            'wallet' => $organization->lipr_wallet,
            'narration' => 'Organization wallet deposit',
            'customerAccountNumber' => $phone,
            'recipients' => [['amount' => $amount, 'account' => $phone]],
        ]);
        if (! ($response['data'][0]['success'] ?? false)) {
            return ['success' => false, 'message' => $this->plain($response)];
        }

        return ['success' => true, 'reference' => $response['data'][0]['reference'] ?? null];
    }

    protected function post(string $path, array $fields): array
    {
        $ch = curl_init(rtrim((string) config('services.lipr.base_path'), '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->auth->authorize(), 'Content-Type: application/json'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    private function plain(array $response): string
    {
        // LIPR answers 200 "Transactions initiated successfully" even when it refuses the one payment: its reason is on the item
        $reason = ($response['data'][0]['reason'] ?? null) ?: ($response['message'] ?? $response['error'] ?? null);

        return 'The payment provider refused: ' . (is_string($reason) && $reason !== '' ? rtrim($reason, '.') : 'no reason given') . '.';
    }
}
