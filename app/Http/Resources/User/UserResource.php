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

        $isInvestor = $userTypeId === 2;
        $isServiceProvider = $userTypeId === 3;
        $isOrganization = $userTypeId === 4;
        $isCapital = $userTypeId === 5;

        if ($isOrganization) {
            $this->loadMissing('organization.workspaces', 'organization.programIndustry');
        } elseif ($isInvestor) {
            $this->loadMissing('investor_profile');
        } elseif ($isServiceProvider) {
            $this->loadMissing('service_provider_profile');
        } elseif ($isCapital) {
            $this->loadMissing('capital_profile');
        }

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
                'program_industry_id' => $organization->program_industry_id,
                'program_industry' => $organization->programIndustry?->only(['id', 'name', 'url']),
                'focus_sectors' => $organization->focus_sectors,
                'operating_countries' => $organization->operating_countries,
                'target_regions' => $organization->target_regions,
                'financial_year_start_month' => $organization->financial_year_start_month,
                'lipr_wallet' => $organization->lipr_wallet,
                'stripe_account_id' => $organization->stripe_account_id,
                'status' => $organization->status,
            ];
        }

        $user = [
            'id' => (int) $this->id,
            'user_type_id' => $userTypeId,
            'user_type' => $this->user_type?->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image' => $this->image,
            'completed_onboarding' => (bool) ($this->completed_onboarding ?? false),
            'organization_id' => $this->organization_id,
            'stripe_connect_id' => $this->stripe_connect_id,
            'lipr_wallet_account' => $this->lipr_wallet_account,
            'organization' => $organizationPayload,
            'workspaces' => $organizationWorkspaces,
        ];

        if (!$isOrganization) {
            $user['gender'] = $this->gender;
            $user['dob'] = $this->dob;
            $user['city'] = $this->city;
            $user['country'] = $this->country;
            $user['website'] = $this->website;
        }

        if ($isInvestor) {
            $user['investor_profile'] = $this->investor_profile;
        } elseif ($isServiceProvider) {
            $user['service_provider_profile'] = $this->service_provider_profile;
        } elseif ($isCapital) {
            $user['capital_profile'] = $this->capital_profile;
        }

        return $user;
    }
}
