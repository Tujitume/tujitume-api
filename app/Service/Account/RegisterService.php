<?php

namespace App\Service\Account;

use App\Http\Resources\User\UserResource;
use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use App\Models\Users\InvestorProfile;
use App\Models\Users\ServiceProviderProfile;
use App\Service\File\ImageUploadService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterService
{
    public function __construct(private ImageUploadService $imageUpload) {}

    // ─── Business Owner ──────────────────────────────────────────────────
    public function registerBusinessOwner(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:Male,Female,Other'],
            'dob' => ['required', 'date'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        ]);

        $uploadedImage = null;

        DB::beginTransaction();
        try {
            $image = $data['image'] ?? null;

            if ($image) {
                $uploadedImage = $this->imageUpload->save($image, 'images/users');
            }

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'],
                'dob' => $data['dob'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'country' => $data['country'] ?? null,
                'city' => $data['city'] ?? null,
                'website' => $data['website'] ?? null,
                'user_type_id' => 1,
                'image' => $uploadedImage,
            ]);

            UserSetting::create(['user_id' => $user->id]);

            DB::commit();

            return response()->json([
                'message' => 'Registration successful.',
                'user' => $user,
                'token' => $user->createToken('main')->plainTextToken,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteUploadedImage($uploadedImage);
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── Investor ────────────────────────────────────────────────────────
    public function registerInvestor(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:Male,Female,Other'],
            'dob' => ['required', 'date'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'country' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],

            // Investor profile
            'inv_range' => ['required', 'array'],
            'turnover_range' => ['required', 'array'],
            'interested_sectors' => ['required', 'array'],
            'stage' => ['required', 'array'],
            'regions_focus' => ['required', 'array'],
            'social_impact_areas' => ['nullable', 'array'],
            'past_investment' => ['nullable', 'string', 'max:1000'],
        ]);

        $uploadedImage = null;

        DB::beginTransaction();
        try {
            $image = $data['image'] ?? null;

            if ($image) {
                $uploadedImage = $this->imageUpload->save($image, 'images/users');
            }

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'],
                'dob' => $data['dob'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'website' => $data['website'] ?? null,
                'country' => $data['country'] ?? null,
                'city' => $data['city'] ?? null,
                'user_type_id' => 2,
                'image' => $uploadedImage,
            ]);

            InvestorProfile::create([
                'user_id' => $user->id,
                'inv_range' => $data['inv_range'],
                'turnover_range' => $data['turnover_range'],
                'interested_sectors' => $data['interested_sectors'],
                'stage' => $data['stage'],
                'regions_focus' => $data['regions_focus'],
                'social_impact_areas' => $data['social_impact_areas'] ?? null,
                'past_investment' => $data['past_investment'] ?? null,
            ]);

            UserSetting::create(['user_id' => $user->id]);

            DB::commit();

            return response()->json([
                'message' => 'Registration successful.',
                'user' => $user,
                'token' => $user->createToken('main')->plainTextToken,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteUploadedImage($uploadedImage);
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── Service Provider ────────────────────────────────────────────────
    public function registerServiceProvider(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'in:Male,Female,Other'],
            'dob' => ['required', 'date'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'country' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],

            // SP profile
            'supplier_type' => ['required', 'in:material_goods,business_service,labor'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'region' => ['nullable', 'string', 'max:100'],
            'service_areas' => ['nullable', 'array'],
            'work_mode' => ['nullable', 'in:remote,onsite,hybrid'],
            'available_days' => ['nullable', 'array'],
            'available_from' => ['nullable', 'string', 'max:10'],
            'available_to' => ['nullable', 'string', 'max:10'],
        ]);

        $uploadedImage = null;

        DB::beginTransaction();
        try {
            $image = $data['image'] ?? null;

            if ($image) {
                $uploadedImage = $this->imageUpload->save($image, 'images/users');
            }

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'display_name' => $data['display_name'] ?? null,
                'gender' => $data['gender'],
                'dob' => $data['dob'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'website' => $data['website'] ?? null,
                'country' => $data['country'] ?? null,
                'city' => $data['city'] ?? null,
                'user_type_id' => 3,
                'image' => $uploadedImage,
            ]);

            ServiceProviderProfile::create([
                'user_id' => $user->id,
                'supplier_type' => $data['supplier_type'],
                'bio' => $data['bio'] ?? null,
                'region' => $data['region'] ?? null,
                'service_areas' => $data['service_areas'] ?? null,
                'work_mode' => $data['work_mode'] ?? 'hybrid',
                'available_days' => $data['available_days'] ?? null,
                'available_from' => $data['available_from'] ?? null,
                'available_to' => $data['available_to'] ?? null,
            ]);

            UserSetting::create(['user_id' => $user->id]);

            DB::commit();

            return response()->json([
                'message' => 'Registration successful.',
                'user' => $user,
                'token' => $user->createToken('main')->plainTextToken,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteUploadedImage($uploadedImage);
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // ─── Organization ────────────────────────────────────────────────────
    public function registerOrganization(Request $request)
    {
        $data = $request->validate([
            // Owner
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'phone' => ['nullable', 'string', 'max:50'],

            // Organization
            'org_name' => ['required', 'string', 'max:255'],
            'org_display_name' => ['required', 'string', 'max:255'],
            'org_legal_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', 'in:company,ngo,foundation,government,cooperative,other'],
            'year_established' => ['required', 'integer', 'min:1800', 'max:'.date('Y')],
            'org_email' => ['required', 'email'],
            'org_phone' => ['required', 'string', 'max:50'],
            'org_website' => ['required', 'url', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],

            // Industry
            'program_industry_id' => ['required', 'integer', 'exists:program_industries,id'],
            'focus_sectors' => ['required', 'array'],
            'operating_countries' => ['required', 'array'],
            'target_regions' => ['required', 'array'],

            // Location
            'country' => ['required', 'string', 'max:10'],
            'region' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],

            // Financial
            'financial_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],

            // Workspace
            'workspace_slug' => [
                'required',
                'string',
                'max:100',
                'unique:workspaces,slug',
                'regex:/^[a-z0-9\-]+$/',
            ],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        ]);

        $uploadedImage = null;

        DB::beginTransaction();
        try {
            $image = $data['image'] ?? null;

            if ($image) {
                $uploadedImage = $this->imageUpload->save($image, 'images/users');
            }

            // Create owner user
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'display_name' => $data['org_display_name'] ?? $data['org_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'user_type_id' => 4,
                'image' => $uploadedImage,
            ]);

            // Create organization
            $organization = Organization::create([
                'owner_user_id' => $user->id,
                'name' => $data['org_name'],
                // 'display_name'               => $data['org_display_name'] ?? $data['org_name'],
                'legal_name' => $data['org_legal_name'] ?? null,
                'organization_type' => $data['organization_type'],
                'year_established' => $data['year_established'] ?? null,
                'email' => $data['org_email'] ?? null,
                'phone' => $data['org_phone'] ?? null,
                'website' => $data['org_website'] ?? null,
                'description' => $data['description'] ?? null,
                'program_industry_id' => $data['program_industry_id'] ?? null,
                'focus_sectors' => $data['focus_sectors'] ?? null,
                'operating_countries' => $data['operating_countries'] ?? null,
                'target_regions' => $data['target_regions'] ?? null,
                'country' => $data['country'] ?? null,
                'region' => $data['region'] ?? null,
                'city' => $data['city'] ?? null,
                'financial_year_start_month' => $data['financial_year_start_month'] ?? 1,
                'status' => 'pending_verification',
            ]);

            // Create workspace
            Workspace::create([
                'organization_id' => $organization->id,
                'name' => $data['org_name'],
                'slug' => $data['workspace_slug'],
                'subdomain' => $data['workspace_slug'],
                'domain_status' => 'pending',
                'workspace_status' => 'pending_verification',
            ]);

            // Link user to org
            $user->update(['organization_id' => $organization->id]);

            // Every organization user has an explicit organization-scoped role.
            // The registering owner is always the organization's super admin.
            OrganizationUserRole::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_id' => Role::where('name', 'super_admin')->value('id')
                    ?? throw new \RuntimeException('The super_admin role has not been seeded.'),
                'status' => 'active',
            ]);

            // Create default settings
            UserSetting::create([
                'user_id' => $user->id,
                'default_currency' => $data['default_currency'] ?? 'USD',
                'default_language' => $data['default_language'] ?? 'en',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Organization registered successfully.',
                'user' => new UserResource($user->load('organizationRoles.role')),
                'organization' => $organization->load('workspaces'),
                'token' => $user->createToken('main')->plainTextToken,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            $this->deleteUploadedImage($uploadedImage);

            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    private function deleteUploadedImage(?string $uploadedImage): void
    {
        try {
            $this->imageUpload->delete($uploadedImage);
        } catch (\Throwable $cleanupException) {
            ErrorLogService::report($cleanupException, ['image' => $uploadedImage]);
        }
    }

    // ─── Org Role User (team member) ─────────────────────────────────────
    public function registerOrgRoleUser(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'email' => ['required', 'email', 'unique:users,email'],
            'first_name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        ]);

        $organization = Organization::findOrFail($data['organization_id']);
        $inviter = $request->user();
        $isOrganizationOwner = $inviter?->id === $organization->owner_user_id;
        $isOrganizationAdmin = $inviter?->organizationRole()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'super_admin'))
            ->exists();

        if (! $isOrganizationOwner && ! $isOrganizationAdmin) {
            return response()->json(['message' => 'Only an organization super admin can invite team members.'], 403);
        }

        $uploadedImage = null;

        DB::beginTransaction();
        try {
            $image = $data['image'] ?? null;

            if ($image) {
                $uploadedImage = $this->imageUpload->save($image, 'images/users');
            }

            $invitationToken = Str::random(64);

            $user = User::create([
                'first_name' => $data['first_name'],
                'email' => $data['email'],
                'password' => null,
                'user_type_id' => 4,
                'organization_id' => $organization->id,
                'image' => $uploadedImage,
            ]);

            UserSetting::create(['user_id' => $user->id]);

            OrganizationUserRole::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_id' => $data['role_id'],
                'status' => 'pending',
                'invited_by_user_id' => $inviter->id,
                'invited_at' => now(),
                'invitation_token_hash' => hash('sha256', $invitationToken),
                'invitation_expires_at' => now()->addDays(7),
            ]);

            DB::commit();

            $invitationUrl = rtrim(config('app.app_url'), '/')
                .'/organization-invitations/accept?token='.$invitationToken;

            try {
                Mail::send('organization_team_invitation', [
                    'teamMember' => $user,
                    'organization' => $organization,
                    'inviter' => $inviter,
                    'role' => Role::find($data['role_id'])->name,
                    'invitationUrl' => $invitationUrl,
                    'expiresAt' => now()->addDays(7),
                ], function ($message) use ($user, $organization): void {
                    $message->to($user->email)
                        ->subject("You've been invited to join {$organization->name}");
                });
            } catch (\Throwable $mailException) {
                // The pending membership remains valid so the invite can be resent.
                ErrorLogService::report($mailException, [
                    'organization_id' => $organization->id,
                    'team_member_id' => $user->id,
                ]);
            }

            return response()->json([
                'message' => 'Team member invited successfully.',
                'user' => new UserResource($user->load('organizationRoles.role')),
                'invitation_expires_at' => now()->addDays(7)->toISOString(),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteUploadedImage($uploadedImage);
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
