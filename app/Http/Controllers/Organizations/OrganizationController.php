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
    // ─── SME Organization ───────────────────────────────────────────────
    public function createOrganization(Request $request)
    {
        $user = $request->user();

        // Only business owners can create an SME organization.
        if ((int) $user->user_type_id !== 1) {
            return response()->json([
                'message' => 'Only business owners can create an organization.',
            ], 403);
        }

        // Prevent creating multiple organizations if your current model
        // allows only one organization per business owner.
        if ($user->organization_id) {
            return response()->json([
                'message' => 'You already have an organization.',
            ], 409);
        }

        $data = $request->validate([
            // Business Identity
            'legal_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:100'],
            'registration_date' => ['required', 'date'],
            'registration_country' => ['required', 'string', 'size:2'],
            'legal_structure' => ['required', 'string', 'max:50'],

            // Organization
            'organization_type' => [
                'required',
                'in:company,ngo,foundation,government,cooperative,other',
            ],

            // Industry
            'program_industry_id' => [
                'required',
                'integer',
                'exists:program_industries,id',
            ],
            'sector' => ['required', 'string', 'max:150'],
            'sub_sector' => ['required', 'string', 'max:150'],

            // Business description
            'description' => ['required', 'string'],

            // Location
            'physical_address' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:10'],

            // Business contact
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'social_media_url' => ['nullable', 'url', 'max:255'],
        ]);

        DB::beginTransaction();

        try {
            // Create organization
            $organization = Organization::create([
                'owner_user_id' => $user->id,

                'name' => $data['legal_name'],
                'legal_name' => $data['legal_name'],
                'trading_name' => $data['trading_name'] ?? null,

                'registration_number' => $data['registration_number'],
                'registration_date' => $data['registration_date'],
                'registration_country' => $data['registration_country'],
                'legal_structure' => $data['legal_structure'],

                'organization_type' => $data['organization_type'],

                'program_industry_id' => $data['program_industry_id'],
                'sector' => $data['sector'],
                'sub_sector' => $data['sub_sector'],

                'description' => $data['description'],

                'physical_address' => $data['physical_address'],
                'country' => $data['country'],
                'region' => $data['region'],
                'city' => $data['city'],

                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'website' => $data['website'] ?? null,
                'social_media_url' => $data['social_media_url'] ?? null,

                'status' => 'pending_verification',
            ]);

            // Link the SME user to the organization.
            $user->update([
                'organization_id' => $organization->id,
            ]);

            // Create organization owner role.
            OrganizationUserRole::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_id' => Role::where('name', 'super_admin')->value('id')
                    ?? throw new \RuntimeException(
                        'The super_admin role has not been seeded.'
                    ),
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Organization created successfully.',
                'data' => [
                    'organization' => $organization->fresh(),
                    'user' => $user->fresh()->load('organizationRoles.role'),
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            ErrorLogService::report($e, [
                'input' => $request->except(['password', 'token']),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.',
            ], 500);
        }
    }


    public function updateOrganization(Request $request, Organization $organization)
    {
        $user = $request->user();

        if ((int) $user->user_type_id !== 1) {
            return response()->json([
                'message' => 'Only business owners can update an organization.',
            ], 403);
        }

        // Ensure this user owns this organization.
        if ((int) $organization->owner_user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'You are not authorized to update this organization.',
            ], 403);
        }

        // Do not allow business identity changes after verification.
        if ($organization->status === 'verified') {
            return response()->json([
                'message' => 'A verified organization cannot be modified.',
            ], 409);
        }

        $data = $request->validate([
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'trading_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'registration_number' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'registration_date' => [
                'sometimes',
                'date',
            ],

            'registration_country' => [
                'sometimes',
                'string',
                'size:2',
            ],

            'legal_structure' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'organization_type' => [
                'sometimes',
                'in:company,ngo,foundation,government,cooperative,other',
            ],

            'program_industry_id' => [
                'sometimes',
                'integer',
                'exists:program_industries,id',
            ],

            'sector' => [
                'sometimes',
                'string',
                'max:150',
            ],

            'sub_sector' => [
                'sometimes',
                'string',
                'max:150',
            ],

            'description' => [
                'sometimes',
                'string',
            ],

            'physical_address' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'region' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'city' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'country' => [
                'sometimes',
                'string',
                'max:10',
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'website' => [
                'sometimes',
                'nullable',
                'url',
                'max:255',
            ],

            'social_media_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:255',
            ],
        ]);

        DB::beginTransaction();

        try {
            // Keep `name` synchronized with the business's display name.
            if (
                array_key_exists('trading_name', $data)
                || array_key_exists('legal_name', $data)
            ) {

                $tradingName = $data['trading_name']
                    ?? $organization->trading_name;

                $legalName = $data['legal_name']
                    ?? $organization->legal_name;

                $data['name'] = $tradingName ?: $legalName;
            }

            $organization->update($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Organization updated successfully.',
                'data' => [
                    'organization' => $organization->fresh(),
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            ErrorLogService::report($e, [
                'input' => $request->all(),
                'user_id' => $user->id,
                'organization_id' => $organization->id,
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.',
            ], 500);
        }
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
