<?php

namespace App\Http\Resources\Grant\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MESubmissionFileResource extends JsonResource
{
    /**
     * Transform a submission file into its monitoring API representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_type' => $this->file_type,
            'file_path' => $this->file_path,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
