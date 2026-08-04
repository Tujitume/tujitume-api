<?php

namespace App\Http\Resources\Grant\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'file_url'          => $this->file_path
                ? Storage::disk('s3')->temporaryUrl($this->file_path, now()->addMinutes(45))
                : null,
            'mime_type' => $this->mime_type,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
