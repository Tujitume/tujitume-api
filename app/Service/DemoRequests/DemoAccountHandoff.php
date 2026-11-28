<?php

namespace App\Service\DemoRequests;

use App\Models\Auth\User;
use App\Models\DemoRequest;
use App\Models\Organizations\Organization;
use App\Service\Notification\RecipientUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin › Demo Requests › "Open demo account": signs the admin into a 7-day demo account in a new tab, to see what
 * the requester sees. Only ever a demo account (a trial organization), never a real one.
 *
 *  issue()   a one-time code, good for 2 minutes, in a link to the app's /auth/admin-view page
 *  redeem()  the app trades the code for a sign-in that lasts 2 hours, named after the admin
 */
class DemoAccountHandoff
{
    public const CODE_MINUTES = 2;

    public const SIGN_IN_HOURS = 2;

    public const TOKEN_PREFIX = 'admin-view:';

    /** @return string the link that opens the demo account */
    public function issue(DemoRequest $request, int $adminId): string
    {
        $user = $this->demoUser($request->trial_user_id, $request->trial_organization_id);
        if (! $user || ! $request->hasTrial()) {
            throw new \RuntimeException('This request has no open demo account.');
        }

        $code = Str::random(64);
        Cache::put(self::key($code), [
            'user_id' => $user->id,
            'organization_id' => $request->trial_organization_id,
            'admin_id' => $adminId,
            'demo_request_id' => $request->id,
        ], now()->addMinutes(self::CODE_MINUTES));

        return RecipientUrl::url('auth/admin-view?code='.$code, $user);
    }

    /** @return string|null a sign-in token for the demo account, once per code */
    public function redeem(?string $code): ?string
    {
        if (! is_string($code) || strlen($code) !== 64) return null;

        $grant = Cache::pull(self::key($code));
        if (! is_array($grant)) return null;

        $user = $this->demoUser($grant['user_id'] ?? null, $grant['organization_id'] ?? null);
        if (! $user) return null;

        Log::info('Admin opened a demo account', $grant);

        return $user->createToken(self::TOKEN_PREFIX.$grant['admin_id'], ['*'], now()->addHours(self::SIGN_IN_HOURS))->plainTextToken;
    }

    /** The demo's own person, while its organization is still a trial */
    private function demoUser(?int $userId, ?int $organizationId): ?User
    {
        if (! $userId || ! $organizationId) return null;
        if (! Organization::whereKey($organizationId)->where('is_trial', true)->exists()) return null;

        return User::whereKey($userId)->where('organization_id', $organizationId)->first();
    }

    private static function key(string $code): string
    {
        return 'demo_admin_view:'.hash('sha256', $code);
    }
}
