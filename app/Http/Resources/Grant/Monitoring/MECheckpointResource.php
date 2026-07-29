<?php

namespace App\Http\Resources\Grant\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MECheckpointResource extends JsonResource
{
    /**
     * Transform the checkpoint into its monitoring API representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'checkpoint_name' => $this->checkpoint_name,
            'type' => $this->type,
            'due_date' => $this->due_date?->toDateString(),
            'requirement' => $this->requirement,
            'require_site_visit' => $this->require_site_visit,
            'kpis_to_track' => $this->kpis_to_track,
            'evidence_required' => $this->evidence_required,
            'submission_fields' => $this->submission_fields,
            'custom_submission_fields' => $this->custom_submission_fields,
            'display_order' => $this->display_order,
            'status' => [
                'value' => $this->status,
                'color' => config("status.me_checkpoint.{$this->status}", 'gray'),
            ],
            'submission' => new MESubmissionResource($this->whenLoaded('submission')),
            'site_visit' => new MESiteVisitResource($this->whenLoaded('siteVisit')),
        ];
    }
}
