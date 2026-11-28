<?php

namespace App\Http\Resources\Program\Monitoring;

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
            // ISSUE-34: a file link that cannot be made (storage not set up, file missing) must not fail the whole reply
            'file_url'          => $this->safeUrl(),
            'mime_type' => $this->mime_type,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function safeUrl(): ?string
    {
        if (! $this->file_path) return null;
        try {
            return Storage::disk('s3')->temporaryUrl($this->file_path, now()->addMinutes(45));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Checkpoint file link could not be made', ['file_id' => $this->id, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
