<?php

namespace App\Service\DemoRequests;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Business\BusinessSubscriptions;
use App\Models\DemoRequest;
use App\Models\Organizations\Organization;
use App\Models\Organizations\Workspace;
use App\Models\ProgramIndustry;
use App\Models\Programs\Program;
use App\Service\Account\ProgramDataPurger;
use App\Service\Notification\RecipientUrl;
use App\Service\Organization\WorkspaceDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The 7-day trial program-provider account behind an approved "Book a Demo" request.
 *
 *  create()  user (type 4, no password yet) + trial organization + workspace + super_admin membership whose
 *            invitation token is the "set your password" link + settings on the requester's zone + a 7-day trial
 *            subscription + one hidden sample program for the industry they picked.
 *  purge()   deletes all of it again: every person in the trial organization, its programs and applications.
 *
 * Programs of a trial organization never become visible to applicants (Program::saving).
 */
class TrialAccountService
{
    public function __construct(private ProgramDataPurger $programs = new ProgramDataPurger())
    {
    }

    /** @return string the link that lets the requester set a password and sign in */
    public function create(DemoRequest $request): string
    {
        if ($request->trial_user_id && User::whereKey($request->trial_user_id)->exists()) {
            throw new \RuntimeException('This request already has a trial account.');
        }
        if (User::whereRaw('LOWER(email) = ?', [strtolower($request->email)])->exists()) {
            throw new \RuntimeException('An account with '.$request->email.' already exists, so a trial account cannot be created for it.');
        }

        $token = Str::random(64);
        $expiresAt = now()->addDays(DemoRequest::TRIAL_DAYS);
        $industryId = $request->program_industry_id
            ?: ProgramIndustry::where('url', 'other')->value('id');

        DB::transaction(function () use ($request, $token, $expiresAt, $industryId) {
            [$first, $last] = $this->splitName($request->name);

            $user = User::create([
                'first_name' => $first,
                'last_name' => $last,
                'display_name' => $request->org,
                'email' => strtolower(trim($request->email)),
                'password' => null,
                'user_type_id' => 4,
                'email_verified_at' => now(),
                'invited_at' => now(),
            ]);

            $organization = Organization::create([
                'owner_user_id' => $user->id,
                'name' => $request->org,
                'legal_name' => $request->org,
                'organization_type' => 'other',
                'email' => $user->email,
                'program_industry_id' => $industryId,
                'focus_sectors' => [],
                'operating_countries' => [],
                'target_regions' => [],
                'financial_year_start_month' => 1,
                'status' => 'pending_verification',
                'is_trial' => true,
                'trial_expires_at' => $expiresAt,
            ]);

            Workspace::create([
                'organization_id' => $organization->id,
                'name' => $request->org,
                'slug' => $slug = $this->uniqueSlug($request->org),
                'subdomain' => $slug,
                'domain_status' => 'pending',
                'workspace_status' => 'pending_verification',
            ]);

            $user->update(['organization_id' => $organization->id]);

            // The owner's membership starts pending: accepting its invitation (setting a password) makes it active
            OrganizationUserRole::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_id' => Role::where('name', 'super_admin')->value('id')
                    ?? throw new \RuntimeException('The super_admin role has not been seeded.'),
                'status' => 'pending',
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => $expiresAt,
            ]);

            UserSetting::create([
                'user_id' => $user->id,
                'timezone' => $request->displayTimezone(),
                'currency' => 'KES',
            ]);

            BusinessSubscriptions::create([
                'plan' => 'gold',
                'investor_id' => $user->id,
                'amount' => 0,
                'method' => 'lipr',
                'start_date' => now(),
                'expire_date' => $expiresAt,
                'token_remaining' => config('subscriptions.tokens.gold') ?? 0,
                'trial' => 1,
                'lipr_payment_id' => null,
            ]);

            $this->createSampleProgram($user, $organization, $industryId);

            $request->update([
                'status' => DemoRequest::STATUS_TRIAL_ACTIVE,
                'trial_user_id' => $user->id,
                'trial_organization_id' => $organization->id,
                'trial_started_at' => now(),
                'trial_expires_at' => $expiresAt,
                'trial_reminder_sent_at' => null,
                'trial_closed_at' => null,
            ]);
        });

        return $this->accessLink($request->fresh(), $token);
    }

    /** A fresh "set your password" link (the old one stops working). Only while the password has not been set. */
    public function resendAccess(DemoRequest $request): ?string
    {
        $membership = OrganizationUserRole::where('user_id', $request->trial_user_id)
            ->where('organization_id', $request->trial_organization_id)
            ->where('status', 'pending')
            ->first();
        if (! $membership) return null;

        $token = Str::random(64);
        $membership->update([
            'invitation_token_hash' => hash('sha256', $token),
            'invitation_expires_at' => $request->trial_expires_at,
        ]);

        return $this->accessLink($request, $token);
    }

    public function extend(DemoRequest $request, int $days): void
    {
        $expiresAt = ($request->trial_expires_at && $request->trial_expires_at->isFuture() ? $request->trial_expires_at : now())
            ->copy()->addDays($days);

        DB::transaction(function () use ($request, $expiresAt) {
            $request->update(['trial_expires_at' => $expiresAt, 'trial_reminder_sent_at' => null]);
            Organization::whereKey($request->trial_organization_id)->update(['trial_expires_at' => $expiresAt]);
            OrganizationUserRole::where('organization_id', $request->trial_organization_id)
                ->where('status', 'pending')->whereNotNull('invitation_token_hash')
                ->update(['invitation_expires_at' => $expiresAt]);
            BusinessSubscriptions::where('investor_id', $request->trial_user_id)->where('trial', 1)
                ->update(['expire_date' => $expiresAt, 'active' => 1]);
        });
    }

    /**
     * Deletes the trial account and everything made in it. The request keeps its history; only the links to the
     * deleted rows are cleared.
     */
    public function purge(DemoRequest $request, string $finalStatus): void
    {
        $organizationId = $request->trial_organization_id;

        DB::transaction(function () use ($request, $organizationId, $finalStatus) {
            if ($organizationId && Organization::whereKey($organizationId)->where('is_trial', true)->exists()) {
                $userIds = User::where('organization_id', $organizationId)->pluck('id')
                    ->merge(OrganizationUserRole::where('organization_id', $organizationId)->pluck('user_id'))
                    ->push($request->trial_user_id)->filter()->unique()->values()->all();

                $this->programs->forOrganization($organizationId, $userIds);

                BusinessSubscriptions::whereIn('investor_id', $userIds)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)->delete();
                OrganizationUserRole::where('organization_id', $organizationId)->delete();
                Workspace::where('organization_id', $organizationId)->delete();
                Organization::whereKey($organizationId)->delete();
                // Only the trial's own people: an account that also belongs elsewhere would never be in a trial org
                User::whereIn('id', $userIds)->where(fn ($q) => $q->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id'))->delete();
            }

            $request->update([
                'status' => $finalStatus,
                'trial_closed_at' => now(),
            ]);
        });
    }

    /**
     * Frees the trial's email address so the requester can sign up for real with it (the invite-only
     * /auth/create/program page). The trial account stops being reachable; its data is deleted after payment,
     * or when the trial would have ended.
     */
    public function releaseEmail(DemoRequest $request): void
    {
        $user = $request->trial_user_id ? User::find($request->trial_user_id) : null;
        if (! $user || ! str_contains((string) $user->email, '@') || str_ends_with($user->email, '@trial.invalid')) return;

        // The phone goes with the email: the full account usually signs up with the same one
        $user->update(['email' => 'trial-'.$user->id.'-'.Str::lower(Str::random(6)).'@trial.invalid', 'phone' => null]);
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
    }

    private function accessLink(DemoRequest $request, string $token): string
    {
        $user = User::find($request->trial_user_id);

        return RecipientUrl::url('organization-invitations/accept?token='.$token, $user);
    }

    private function createSampleProgram(User $user, Organization $organization, ?int $industryId): void
    {
        $industry = $industryId ? ProgramIndustry::find($industryId) : null;
        $label = $industry && $industry->url !== 'other' ? $industry->name : 'Capital';

        Program::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'program_industry_id' => $industryId,
            'program_title' => 'Sample '.$label.' Program',
            'program_description' => 'A sample program created for your 7-day demo. Explore how applications, review rounds, '
                .'milestones, evidence and staged fund releases work. It is only visible to your demo account and is '
                .'deleted with it when the demo ends.',
            // Tujitume works in Kenyan shillings for now
            'total_program_amount' => 5000000,
            'available_amount' => 5000000,
            'funding_per_business' => 250000,
            'program_type' => 'single_round',
            'total_rounds' => 1,
            'application_start_date' => now()->toDateString(),
            'application_deadline' => now()->addDays(30)->toDateString(),
            'require_kyc_verification_to_apply' => false,
            'use_match_score' => false,
            'status' => 'draft',
            'visible' => false,
            'currency' => 'KES',
        ]);
    }

    private function uniqueSlug(string $org): string
    {
        $base = Str::slug($org) ?: 'demo';
        $base = substr($base, 0, 60).'-demo';

        do {
            $slug = $base.'-'.Str::lower(Str::random(4));
        } while (Workspace::where('slug', $slug)->exists() || in_array($slug, WorkspaceDirectory::RESERVED_SLUGS, true));

        return $slug;
    }

    /** @return array{0:string,1:string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }
}
