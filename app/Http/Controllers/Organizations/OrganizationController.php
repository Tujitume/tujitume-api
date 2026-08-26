<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Auth\OrganizationUserRole;
use App\Models\Organizations\Organization;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Rounds\RoundReviewer;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function reviewers(Request $request, Organization $organization)
    {
        $user = $request->user();

        $isOrganizationMember = OrganizationUserRole::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('role_id', 10001) // Assuming 10001 is the role ID for reviewers
            ->exists();

        if (! $isOrganizationMember) {
            return response()->json(['message' => 'You do not have access to this organization.'], 403);
        }

        $reviewerMemberships = OrganizationUserRole::query()
            ->where('organization_id', $organization->id)
            ->where('role_id', 10004)
            //->where('status', 'active')
            ->with([
                'role:id,name,access_types',
                'user:id,user_type_id,first_name,last_name,display_name,email,phone,image,organization_id',
            ])
            ->orderBy('created_at')
            ->get();

        $reviewerIds = $reviewerMemberships->pluck('user_id')->unique()->values();

        $roundIdsByReviewer = RoundReviewer::query()
            ->whereIn('user_id', $reviewerIds)
            ->orderBy('round_id')
            ->get(['user_id', 'round_id'])
            ->groupBy('user_id')
            ->map(fn ($assignments) => $assignments->pluck('round_id')->values()->all());

        $siteVisitIdsByReviewer = MESiteVisit::query()
            ->whereIn('reviewer_id', $reviewerIds)
            ->orderBy('id')
            ->get(['reviewer_id', 'id'])
            ->groupBy('reviewer_id')
            ->map(fn ($assignments) => $assignments->pluck('id')->values()->all());

        $reviewers = $reviewerMemberships
            ->map(fn (OrganizationUserRole $membership) => [
                'id' => $membership->user->id,
                'first_name' => $membership->user->first_name,
                'last_name' => $membership->user->last_name,
                'display_name' => $membership->user->display_name,
                'email' => $membership->user->email,
                'phone' => $membership->user->phone,
                'image' => $membership->user->image,
                'organization_id' => $membership->organization_id,
                'role' => [
                    'id' => $membership->role->id,
                    'name' => $membership->role->name,
                    'access_types' => $membership->role->access_types,
                ],
                'membership' => [
                    'id' => $membership->id,
                    'status' => $membership->status,
                    'accepted_at' => $membership->accepted_at?->toISOString(),
                ],
                'program_rounds_assigned' => [
                    'round_ids' => $roundIdsByReviewer->get($membership->user_id, []),
                    'count' => count($roundIdsByReviewer->get($membership->user_id, [])),
                ],
                'site_visits_assigned' => [
                    'site_visit_ids' => $siteVisitIdsByReviewer->get($membership->user_id, []),
                    'count' => count($siteVisitIdsByReviewer->get($membership->user_id, [])),
                ],
            ])
            ->values();

        return response()->json([
            'organization_id' => $organization->id,
            'reviewers' => $reviewers,
        ]);
    }
}
