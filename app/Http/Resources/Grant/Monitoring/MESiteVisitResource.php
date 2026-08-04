<?php

namespace App\Http\Resources\Grant\Monitoring;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MESiteVisitResource extends JsonResource
{
    /**
     * Transform a site visit into its monitoring API representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inspector' => $this->inspector,
            'start_date' => $this->start_date?->toDateString(),
            'location' => $this->location,
            'gps_lat' => $this->gps_lat,
            'gps_lng' => $this->gps_lng,
            'objective' => $this->objective,
            'kpi_targets' => $this->kpi_targets,
            'data_collection_fields' => $this->data_collection_fields,
            'objectives_assessment' => $this->objectives_assessment,
            'observed_actions' => $this->observed_actions,
            'evidence_found' => $this->evidence_found,
            'risk_notes' => $this->risk_notes,
            'recommendation_notes' => $this->recommendation_notes,
            'visit_comments' => $this->visit_comments,
            'status' => $this->status,
            'assign_type' => $this->assign_type,
            'email' => $this->email,
            'reviewer' => $this->reviewer?->only(['id', 'fname', 'lname', 'email','image']),
            'files' => MESiteVisitFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
