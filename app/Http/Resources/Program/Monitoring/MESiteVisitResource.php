<?php

namespace App\Http\Resources\Program\Monitoring;

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
        $reviewer = $this->reviewer;
        $service = $reviewer ? \App\Models\Services\Services::where('user_id', $reviewer->id)
            ->whereIn('category', ['project_management', 'business_coaching'])
            ->orderByRaw('CASE WHEN lat IS NOT NULL AND lng IS NOT NULL THEN 0 ELSE 1 END')
            ->first() : null;
        return [
            'id' => $this->id,
            'inspector' => $this->inspector,
            'start_date' => $this->start_date?->toDateString(),
            'location' => $this->location,
            'gps_lat' => $this->gps_lat,
            'gps_lng' => $this->gps_lng,
            'confirm_location' => (bool) $this->confirm_location,
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
            'reviewer' => $reviewer ? array_merge($reviewer->only(['id', 'first_name', 'last_name', 'email','image']), [
                'lat' => $service?->lat, 'lng' => $service?->lng, 'service_location' => $service?->location,
                'price' => $service?->price, 'service_name' => $service?->name,
            ]) : null,
            'checkpoint' => $this->whenLoaded('checkpoint', fn () => $this->checkpoint->only([
                'id', 'checkpoint_name', 'type', 'due_date', 'requirement', 'require_site_visit', 'status',
            ])),
            'files' => MESiteVisitFileResource::collection($this->whenLoaded('files')),
            'order' => $this->whenLoaded('reviewerOrder', fn () => $this->reviewerOrder ? [
                'id' => $this->reviewerOrder->id,
                'work_status' => $this->reviewerOrder->work_status,
                'acceptance_status' => $this->reviewerOrder->acceptance_status,
                'payment_status' => $this->reviewerOrder->payment_status,
                'fee_amount' => $this->reviewerOrder->fee_amount,
                'currency' => $this->reviewerOrder->currency,
                'fee_status' => $this->reviewerOrder->fee_status,
                'proposed_fee' => $this->reviewerOrder->proposed_fee,
                'delivered_at' => $this->reviewerOrder->delivered_at?->toISOString(),
                'approved_at' => $this->reviewerOrder->approved_at?->toISOString(),
            ] : null),
        ];
    }
}
