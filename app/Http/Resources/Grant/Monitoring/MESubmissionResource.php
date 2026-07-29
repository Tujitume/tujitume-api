<?php

namespace App\Http\Resources\Grant\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MESubmissionResource extends JsonResource
{
    /**
     * Transform a checkpoint submission into its monitoring API representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'written_report' => $this->written_report,
            'kpi_actual_values' => $this->kpi_actual_values,
            'beneficiary_list' => $this->beneficiary_list,
            'custom_field_values' => $this->custom_field_values,
            'status' => $this->status,
            'reviewer_note' => $this->reviewer_note,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'files' => MESubmissionFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
