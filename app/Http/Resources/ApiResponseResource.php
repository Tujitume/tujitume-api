<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiResponseResource extends JsonResource
{
    /**
     * Return the common envelope used by write endpoints.
     */
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Return a common error envelope for write endpoints.
     */
    public static function error(string $message, mixed $errors = null, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */

    public function __construct(
        public readonly string $message,
        public readonly mixed $data = null,
        public readonly int $status = 200
    ) {
        $resource = [
            'message' => $message,
            'data'    => $data,
            'status'  => $status,
        ];

        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'success' => $this->resource['status'] < 400,
            'message' => $this->resource['message'],
            'data' => $this->resource['data'],
            'status' => $this->resource['status'],
        ];
    }
}
