<?php

namespace App\Service\LiprMpesa;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LiprPreviewService
{
    private const ENDPOINTS = [
        'bank' => '/payments/bank/preview',
        'mobile_money_send' => '/payments/mobile-money/send/preview',
        'paybill' => '/payments/mobile-money/paybill/preview',
        'till' => '/payments/mobile-money/till/preview',
        'pochi' => '/payments/mobile-money/pochi/preview',
        'stk' => '/payments/mobile-money/stk/preview',
        'lipr' => '/payments/lipr/preview',
        'wallet_transfer' => '/wallets/transfer/preview',
    ];

    public function __construct(private LiprAuthService $liprAuth)
    {
    }

    public function supportedTypes(): array
    {
        return array_keys(self::ENDPOINTS);
    }

    public function preview(string $type, array $payload): Response
    {
        if (!isset(self::ENDPOINTS[$type])) {
            throw new \InvalidArgumentException('Unsupported LIPR preview type.');
        }

        $token = $this->liprAuth->authorize();
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Unable to authenticate with LIPR.');
        }

        $url = rtrim(config('services.lipr.base_path'), '/')
            . '/partners/v1'
            . self::ENDPOINTS[$type];

        return Http::timeout(30)
            ->acceptJson()
            ->withToken($token)
            ->post($url, $payload);
    }
}