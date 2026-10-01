<?php

namespace App\Http\Controllers;

use App\Service\LiprMpesa\LiprPreviewService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Client\ConnectionException;

class LiprPreviewController extends Controller
{
    public function __construct(private LiprPreviewService $liprPreviewService)
    {
        parent::__construct();
    }

    public function preview(Request $request): JsonResponse
    {
        $jsonBody = $request->json()->all();
        $requestedType = $request->query('type')
            ?? $request->query('endpoint_name')
            ?? $jsonBody['type']
            ?? $jsonBody['endpoint_name']
            ?? null;

        try {
            $type = $this->normalizeType($requestedType);
            Validator::make(['type' => $type], [
                'type' => ['required', 'string', Rule::in($this->liprPreviewService->supportedTypes())],
            ])->validate();

            $payload = $this->getPayload($request);
            $upstreamResponse = $this->liprPreviewService->preview($type, $payload);

            return response()->json(
                $upstreamResponse->json() ?? ['message' => $upstreamResponse->body()],
                $upstreamResponse->status()
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ConnectionException | \RuntimeException $e) {
            ErrorLogService::report($e, ['preview_type' => $requestedType]);

            return response()->json([
                'message' => 'Unable to connect to the LIPR preview service.',
            ], 502);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['preview_type' => $requestedType]);

            return response()->json([
                'message' => 'Unable to generate payment preview.',
            ], 500);
        }
    }

    private function normalizeType($type): ?string
    {
        if (!is_string($type)) {
            return null;
        }

        $type = strtolower(trim($type, " /\t\n\r\0\x0B"));

        return match ($type) {
            'payments/bank', 'payments/bank/preview' => 'bank',
            'payments/mobile-money/send', 'payments/mobile-money/send/preview', 'mobile-money-send' => 'mobile_money_send',
            'payments/mobile-money/paybill', 'payments/mobile-money/paybill/preview', 'mobile-money-paybill' => 'paybill',
            'payments/mobile-money/till', 'payments/mobile-money/till/preview', 'mobile-money-till' => 'till',
            'payments/mobile-money/pochi', 'payments/mobile-money/pochi/preview', 'mobile-money-pochi' => 'pochi',
            'payments/mobile-money/stk', 'payments/mobile-money/stk/preview', 'stk_push' => 'stk',
            'payments/lipr', 'payments/lipr/preview' => 'lipr',
            'wallets/transfer', 'wallets/transfer/preview' => 'wallet_transfer',
            default => $type,
        };
    }

    private function getPayload(Request $request): array
    {
        $jsonBody = $request->json()->all();
        if ($jsonBody !== []) {
            $payload = $jsonBody['payload'] ?? $jsonBody;
            unset($payload['type'], $payload['endpoint_name']);

            return $this->normalizePayload($payload, 'payload');
        }

        $query = $request->query();
        unset($query['type'], $query['endpoint_name']);

        $payload = $query['payload'] ?? $query;
        unset($payload['type'], $payload['endpoint_name']);

        return $this->normalizePayload($payload, 'payload');
    }

    private function normalizePayload($payload, string $field): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages([
                    $field => ['The payload must contain valid JSON.'],
                ]);
            }
        }

        if (!is_array($payload)) {
            throw ValidationException::withMessages([
                $field => ['The payload must be a JSON object or query parameter set.'],
            ]);
        }

        return $payload;
    }
}