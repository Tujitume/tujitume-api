<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiResponseResource extends JsonResource
{
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
            'message' => $this->resource['message'],
            'data' => $this->resource['data'],
            'status' => $this->resource['status'],
        ];
    }
}
