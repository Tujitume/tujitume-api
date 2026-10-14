<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\Organizations\OrganizationProvider;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;
use Illuminate\Http\Request;

/**
 * The Tujitume service providers an organization has added as third-party reviewers/auditors.
 * Adding one tells them (notification and email); the organization then picks them for a round from its own
 * reviewer list, and sets the fee there.
 */
class OrganizationProviderController extends Controller
{
    private function organizationOf(Request $request): ?Organization
    {
        $user = $request->user();

        return $user?->organization_id ? Organization::find($user->organization_id) : null;
    }

    private function canManage(User $user, Organization $organization): bool
    {
        if ((int) $organization->owner_user_id === (int) $user->id) {
            return true;
        }

        return OrganizationUserRole::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'super_admin'))
            ->exists();
    }

    private function unavailable()
    {
        return response()->json(['message' => 'Third-party providers are not set up yet. Run the latest database migration.'], 503);
    }

    /** GET /organizations/providers */
    public function index(Request $request)
    {
        $organization = $this->organizationOf($request);
        if (! $organization || (int) $request->user()->user_type_id !== 4) {
            return response()->json(['message' => 'You are not part of an organization.'], 403);
        }
        if (! OrganizationProvider::available()) {
            return response()->json(['data' => []], 200);
        }

        $links = OrganizationProvider::where('organization_id', $organization->id)->with('provider')->latest()->get();

        return response()->json([
            'data' => $links->filter(fn ($link) => $link->provider)->map(fn ($link) => [
                'id' => $link->provider->id,
                'name' => $link->provider->display_name ?: trim($link->provider->first_name . ' ' . $link->provider->last_name),
                'email' => $link->provider->email,
                'image' => $link->provider->image,
                'added_at' => $link->created_at?->toISOString(),
            ])->values(),
        ], 200);
    }

    /** POST /organizations/providers  { user_id } */
    public function store(Request $request)
    {
        $user = $request->user();
        $organization = $this->organizationOf($request);

        if (! $organization || (int) $user->user_type_id !== 4 || ! $this->canManage($user, $organization)) {
            return response()->json(['message' => 'Only an organization super admin can add third-party providers.'], 403);
        }
        if (! OrganizationProvider::available()) {
            return $this->unavailable();
        }

        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $provider = User::findOrFail($data['user_id']);

        if ((int) $provider->user_type_id !== 3) {
            return response()->json(['message' => 'Only Tujitume service providers can be added as third-party providers.'], 422);
        }

        $link = OrganizationProvider::firstOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $provider->id],
            ['added_by' => $user->id],
        );

        // Tell them only the first time
        if ($link->wasRecentlyCreated) {
            $this->notifyProvider($provider, $user, $organization);
        }

        return response()->json([
            'message' => $link->wasRecentlyCreated
                ? "{$provider->first_name} was added. They have been told by notification and email."
                : "{$provider->first_name} is already in your list.",
            'already_added' => ! $link->wasRecentlyCreated,
        ], $link->wasRecentlyCreated ? 201 : 200);
    }

    /** DELETE /organizations/providers/{provider} - takes them off the list; their account is untouched. */
    public function destroy(Request $request, User $provider)
    {
        $user = $request->user();
        $organization = $this->organizationOf($request);

        if (! $organization || (int) $user->user_type_id !== 4 || ! $this->canManage($user, $organization)) {
            return response()->json(['message' => 'Only an organization super admin can remove third-party providers.'], 403);
        }
        if (! OrganizationProvider::available()) {
            return $this->unavailable();
        }

        OrganizationProvider::where('organization_id', $organization->id)->where('user_id', $provider->id)->delete();

        return response()->json(['message' => 'Removed from your list. Their account is not affected.'], 200);
    }

    private function notifyProvider(User $provider, User $actor, Organization $organization): void
    {
        try {
            $orgName = $organization->display_name ?: $organization->name;
            $title = "{$orgName} added you as a third-party reviewer";
            $body = "{$orgName} added you to its list of third-party reviewers and auditors on Tujitume. You may now receive review and audit requests from them.";
            $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: $orgName;
            $url = rtrim((string) config('app.app_url'), '/') . '/dashboard/service-provider/grants-discover';

            (new EmailService())->send($title, 'organization_provider_added', [
                'brand' => EmailBrand::forOrganization($organization),
                'title' => $title,
                'body' => $body,
                'recipientName' => $provider->first_name ?: ($provider->display_name ?: 'there'),
                'organization' => $organization,
                'actorName' => $actorName,
                'url' => $url,
            ], $provider->email);

            // headline, then the sentence (the bell splits them at the line break)
            (new NotificationService())->create(
                $provider->id, $actor->id, $title . "\n" . $body, 'dashboard.serviceProvider.grantsDiscover', 'program'
            );
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['provider_id' => $provider->id, 'organization_id' => $organization->id]);
        }
    }
}
