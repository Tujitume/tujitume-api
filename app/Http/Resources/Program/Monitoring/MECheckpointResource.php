<?php

namespace App\Http\Resources\Program\Monitoring;

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
            'id' => $this->whenHas('id', $this->id),
            'checkpoint_name' => $this->whenHas('checkpoint_name', $this->checkpoint_name),
            'type' => $this->whenHas('type', $this->type),
            'due_date' => $this->whenHas('due_date', $this->due_date?->toDateString()),
            'requirement' => $this->whenHas('requirement', $this->requirement),
            'require_site_visit' => $this->whenHas('require_site_visit', $this->require_site_visit),
            'meeting_required' => $this->whenHas('meeting_required', $this->meeting_required),
            'meeting_id' => $this->whenHas('meeting_id', $this->meeting_id),
            'kpis_to_track' => $this->whenHas('kpis_to_track', $this->kpis_to_track),
            'evidence_required' => $this->whenHas('evidence_required', $this->evidence_required),
            'submission_fields' => $this->whenHas('submission_fields', $this->submission_fields),
            'custom_submission_fields' => $this->whenHas('custom_submission_fields', $this->custom_submission_fields),
            'display_order' => $this->whenHas('display_order', $this->display_order),
            'status' => $this->whenHas('status', [
                'value' => $this->status,
                'color' => config("status.me_checkpoint.{$this->status}", 'gray'),
            ]),
            'submission' => new MESubmissionResource($this->whenLoaded('submission')),
            'site_visit' => new MESiteVisitResource($this->whenLoaded('siteVisit')),
        ];
    }
}
