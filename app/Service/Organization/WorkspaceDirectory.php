<?php

namespace App\Service\Organization;

use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Organizations\Workspace;

/**
 * Finds an organisation's workspace from the name in its address (mastercard.tujitume.com -> "mastercard")
 * and describes how that workspace's sign-in page should look. Everything here is safe to show to someone who
 * is not signed in: a name, a logo and colours, never contact details or ids of people.
 */
class WorkspaceDirectory
{
    /**
     * Names the platform itself uses as addresses. They can never belong to an organisation, otherwise
     * an organisation could take over api.tujitume.com or www.tujitume.com.
     */
    public const RESERVED_SLUGS = [
        'www', 'api', 'api-beta', 'beta', 'app', 'admin', 'mail', 'email', 'smtp', 'ftp', 'cdn', 'static', 'assets',
        'dashboard', 'login', 'auth', 'staging', 'dev', 'test', 'support', 'help', 'status', 'docs', 'blog',
        'tujitume',
    ];

    /** Lower-cased slug, or null when it could not be a workspace name at all. */
    public static function normalize(?string $slug): ?string
    {
        $slug = strtolower(trim((string) $slug));

        return $slug !== '' && strlen($slug) <= 100 && preg_match('/^[a-z0-9-]+$/', $slug) === 1 ? $slug : null;
    }

    public static function find(?string $slug): ?Workspace
    {
        $slug = self::normalize($slug);
        if (! $slug || in_array($slug, self::RESERVED_SLUGS, true)) {
            return null;
        }

        return Workspace::with('organization')->where('slug', $slug)->first();
    }

    /** The name people know the organisation by. */
    public static function displayName(Workspace $workspace): string
    {
        $organization = $workspace->organization;

        return $organization?->display_name ?: $organization?->name ?: $workspace->name;
    }

    /**
     * How the workspace presents itself: the organisation's name, logo and the colours its owner chose in
     * Settings. The frontend turns these into the page's theme in the same way it does for the dashboard.
     */
    public static function branding(Workspace $workspace): array
    {
        $ownerId = $workspace->organization?->owner_user_id;
        $settings = $ownerId ? UserSetting::where('user_id', $ownerId)->first() : null;

        return [
            'name'         => self::displayName($workspace),
            'logo'         => $settings?->logo,
            'theme'        => $settings?->theme,
            'mode'         => $settings?->mode,
            'accent_color' => $settings?->accent_color,
            'accent_style' => $settings?->custom['accent_style'] ?? null,
            'gradient'     => $settings?->custom['gradient'] ?? null,
        ];
    }

    /**
     * Why this person may not sign in through the workspace, or null when they may. Everyone who works inside
     * an organisation (its owner, team members and external reviewers) carries that organisation's id.
     */
    public static function denialFor(User $user, ?string $slug): ?string
    {
        if (self::normalize($slug) === null) {
            return null; // the main sign-in page, not a workspace
        }

        $workspace = self::find($slug);
        if (! $workspace) {
            return 'This workspace does not exist. Check the address you were given.';
        }

        $name = self::displayName($workspace);

        if ($workspace->workspace_status === 'suspended') {
            return "The {$name} workspace is not available right now. Please contact {$name}.";
        }

        if ((int) $user->organization_id !== (int) $workspace->organization_id) {
            return "This account is not part of the {$name} workspace. Use the sign-in link for your own workspace, or sign in at tujitume.com.";
        }

        return null;
    }
}
