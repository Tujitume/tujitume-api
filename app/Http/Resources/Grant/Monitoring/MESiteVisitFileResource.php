<?php

namespace App\Http\Resources\Grant\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Resources\Json\JsonResource;

class MESiteVisitFileResource extends JsonResource
{
    /**
     * Transform a site-visit file into its monitoring API representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_path' => $this->file_path 
            ? Storage::disk('s3')->temporaryUrl($this->file_path, now()->addMinutes(45))
                : null,,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
