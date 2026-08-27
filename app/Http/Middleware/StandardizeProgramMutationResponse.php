<?php

namespace App\Http\Middleware;

use App\Http\Resources\ApiResponseResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every Program write endpoint one predictable response contract while
 * leaving the module's existing read responses intact.
 */
class StandardizeProgramMutationResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (ValidationException $exception) {
            return ApiResponseResource::error('Validation failed.', $exception->errors(), 422);
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (array_key_exists('success', $payload)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $message = $payload['message'] ?? $payload['error'] ?? ($status < 400 ? 'Request completed.' : 'Request failed.');

        if ($status >= 400) {
            return ApiResponseResource::error(
                $message,
                $payload['errors'] ?? ($payload['error'] ?? null),
                $status,
            );
        }

        unset($payload['message'], $payload['status']);
        return ApiResponseResource::success($message, $payload ?: null, $status);
    }
}
