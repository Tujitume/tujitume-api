<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userTypeId = (int) ($this->user_type_id ?? 0);
        $organization = $this->relationLoaded('organization') ? $this->organization : null;
        $organizationWorkspaces = [];

        if ($organization && $organization->relationLoaded('workspaces')) {
            $organizationWorkspaces = $organization->workspaces->map(function ($workspace) {
                return [
                    'id' => $workspace->id,
                    'organization_id' => $workspace->organization_id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'subdomain' => $workspace->subdomain,
                    'custom_domain' => $workspace->custom_domain,
                    'domain_status' => $workspace->domain_status,
                    'workspace_status' => $workspace->workspace_status,
                ];
            })->values()->all();
        }

        $organizationPayload = null;
        if ($organization) {
            $organizationPayload = [
                'id' => $organization->id,
                'owner_user_id' => $organization->owner_user_id,
                'name' => $organization->name,
                'display_name' => $organization->display_name,
                'legal_name' => $organization->legal_name,
                'organization_type' => $organization->organization_type,
                'year_established' => $organization->year_established,
                'description' => $organization->description,
                'email' => $organization->email,
                'phone' => $organization->phone,
                'website' => $organization->website,
                'country' => $organization->country,
                'region' => $organization->region,
                'city' => $organization->city,
                'primary_industry' => $organization->primary_industry,
                'focus_sectors' => $organization->focus_sectors,
                'operating_countries' => $organization->operating_countries,
                'target_regions' => $organization->target_regions,
                'financial_year_start_month' => $organization->financial_year_start_month,
                'lipr_wallet' => $organization->lipr_wallet,
                'stripe_account_id' => $organization->stripe_account_id,
                'status' => $organization->status,
            ];
        }

        return [
            'id' => (int) $this->id,
            'user_type_id' => $userTypeId,
            'user_type' => $this->user_type?->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image,
            'gender' => $this->gender,
            'dob' => $this->dob,
            'country' => $this->country,
            'city' => $this->city,
            'website' => $this->website,
            'completed_onboarding' => (bool) ($this->completed_onboarding ?? false),
            'organization_id' => $this->organization_id,
            'stripe_connect_id' => $this->stripe_connect_id,
            'lipr_wallet_account' => $this->lipr_wallet_account,
            'organization' => $organizationPayload,
            'workspaces' => $organizationWorkspaces,
        ];
    }
}
