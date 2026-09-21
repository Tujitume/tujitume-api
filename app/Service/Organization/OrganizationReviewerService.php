<?php

namespace App\Service\Organization;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Rounds\RoundReviewer;
use Illuminate\Support\Collection;

class OrganizationReviewerService
{
    /**
     * Return internal and external reviewers with their assignment summaries.
     */
    public function forOrganization(Organization $organization): Collection
    {
        $internalReviewerMemberships = $this->internalReviewerMemberships($organization);
        $externalReviewers = $this->externalReviewers($organization);

        $reviewerIds = $internalReviewerMemberships
            ->pluck('user_id')
            ->merge($externalReviewers->pluck('id'))
            ->unique()
            ->values();

        [$roundIdsByReviewer, $siteVisitIdsByReviewer] = $this->assignmentIds($reviewerIds);

        return $internalReviewerMemberships
            ->map(fn (OrganizationUserRole $membership) => $this->reviewerPayload(
                $membership->user,
                'internal',
                $roundIdsByReviewer,
                $siteVisitIdsByReviewer,
                $membership,
            ))
            ->concat($externalReviewers->map(fn (User $reviewer) => $this->reviewerPayload(
                $reviewer,
                'external',
                $roundIdsByReviewer,
                $siteVisitIdsByReviewer,
            )))
            ->values();
    }

    private function internalReviewerMemberships(Organization $organization): Collection
    {
        return OrganizationUserRole::query()
            ->select(['id', 'organization_id', 'user_id', 'role_id', 'status', 'accepted_at', 'created_at'])
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'internal_reviewer'))
            ->whereHas('user', fn ($query) => $query->where('user_type_id', 4))
            ->with([
                'role:id,name,access_types',
                'user' => fn ($query) => $query
                    ->without('user_type')
                    ->select(['id', 'user_type_id', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'image', 'organization_id']),
            ])
            ->orderBy('created_at')
            ->get();
    }

    private function externalReviewers(Organization $organization): Collection
    {
        // External reviewers are linked directly to users.organization_id and
        // intentionally have no organization_user_roles record.
        return User::query()
            // ->without('user_type')
            ->where('organization_id', $organization->id)
            ->where('user_type_id', 6)
            ->orderBy('created_at')
            ->get(['id', 'user_type_id', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'image', 'organization_id']);
    }

    private function assignmentIds(Collection $reviewerIds): array
    {
        if ($reviewerIds->isEmpty()) {
            return [collect(), collect()];
        }

        $roundIdsByReviewer = RoundReviewer::query()
            ->whereIn('user_id', $reviewerIds)
            ->orderBy('round_id')
            ->get(['user_id', 'round_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $assignments) => $assignments->pluck('round_id')->values()->all());

        $siteVisitIdsByReviewer = MESiteVisit::query()
            ->whereIn('reviewer_id', $reviewerIds)
            ->orderBy('id')
            ->get(['reviewer_id', 'id'])
            ->groupBy('reviewer_id')
            ->map(fn (Collection $assignments) => $assignments->pluck('id')->values()->all());

        return [$roundIdsByReviewer, $siteVisitIdsByReviewer];
    }

    private function reviewerPayload(
        User $reviewer,
        string $reviewerType,
        Collection $roundIdsByReviewer,
        Collection $siteVisitIdsByReviewer,
        ?OrganizationUserRole $membership = null,
    ): array {
        $roundIds = $roundIdsByReviewer->get($reviewer->id, []);
        $siteVisitIds = $siteVisitIdsByReviewer->get($reviewer->id, []);

        return [
            'id' => $reviewer->id,
            'user_type_id' => $reviewer->user_type_id,
            'reviewer_type' => $reviewerType,
            'first_name' => $reviewer->first_name,
            'last_name' => $reviewer->last_name,
            'display_name' => $reviewer->display_name,
            'email' => $reviewer->email,
            'phone' => $reviewer->phone,
            'image' => $reviewer->image,
            'organization_id' => $membership?->organization_id ?? $reviewer->organization_id,
            'role' => $membership ? [
                'id' => $membership->role->id,
                'name' => $membership->role->name,
                'access_types' => $membership->role->access_types,
            ] : null,
            'membership' => $membership ? [
                'id' => $membership->id,
                'status' => $membership->status,
                'accepted_at' => $membership->accepted_at?->toISOString(),
            ] : null,
            'program_rounds_assigned' => [
                'round_ids' => $roundIds,
                'count' => count($roundIds),
            ],
            'site_visits_assigned' => [
                'site_visit_ids' => $siteVisitIds,
                'count' => count($siteVisitIds),
            ],
        ];
    }
}
