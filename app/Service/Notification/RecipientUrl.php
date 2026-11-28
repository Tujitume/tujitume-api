<?php

namespace App\Service\Notification;

use App\Models\Auth\User;
use App\Models\Organizations\Workspace;
use App\Service\Organization\WorkspaceDirectory;

/**
 * Where a link sent to a person should open.
 *
 * People who work inside an organisation that has its own workspace (trace.tujitume.com) sign in there,
 * and their session lives on that address only (tokens are per origin). So every link we send them, in an
 * email or anywhere else that leaves the app, has to start with their workspace address. Everyone else
 * (entrepreneurs, investors, service providers, external Business Coaches, guests) gets the main site.
 *
 *   RecipientUrl::baseFor($user)                     https://trace.tujitume.com   or   config('app.app_url')
 *   RecipientUrl::url('dashboard/milestones', $user) https://trace.tujitume.com/dashboard/milestones
 *
 * The recipient may be a User, a user id, an email address or a list of them (one email to several
 * people: their shared workspace, else the main site), so callers can pass whatever they already
 * have (most emails are sent to `$user->email`). No request is needed: queued jobs and scheduled commands
 * get the same answer as a web request.
 *
 * Configuration (config/app.php):
 *   app.app_url                  the main site (APP_URL)
 *   app.workspace_url_pattern    WORKSPACE_URL_PATTERN, e.g. https://{slug}.tujitume.com,
 *                                https://{slug}.beta.tujitume.com (staging), http://{slug}.localhost:81 (local).
 *                                Empty turns workspace links off: everyone gets the main site.
 *   app.workspace_link_statuses  WORKSPACE_LINK_STATUSES, the workspace_status values whose address is used
 *                                (default "active"). Any other workspace falls back to the main site.
 */
class RecipientUrl
{
    /** Account types whose dashboard lives in their organisation's workspace: the organisation's own people. */
    public const WORKSPACE_USER_TYPES = [4];

    /** The main site, without a trailing slash. */
    public static function main(): string
    {
        return rtrim((string) config('app.app_url'), '/');
    }

    /** Address every link for this person starts with, without a trailing slash. */
    public static function baseFor(User|int|string|array|null $recipient): string
    {
        if (is_array($recipient)) return self::baseForAll($recipient);

        $workspace = self::workspaceFor($recipient);

        return ($workspace ? self::workspaceBase($workspace) : null) ?? self::main();
    }

    /**
     * One address for an email sent to several people at once: their shared workspace when they all have the
     * same one, otherwise the main site (which works for everybody).
     */
    public static function baseForAll(iterable $recipients): string
    {
        $bases = [];
        foreach ($recipients as $recipient) {
            $bases[self::baseFor($recipient)] = true;
        }

        return count($bases) === 1 ? array_key_first($bases) : self::main();
    }

    /** Absolute link to a page of the app for this person. $path may carry a query and a #hash. */
    public static function url(string $path, User|int|string|array|null $recipient): string
    {
        return self::join(self::baseFor($recipient), $path);
    }

    /**
     * Moves a link to one of the app's own pages onto this person's address: a link built on the main site,
     * or on another person's workspace address (the same email data reused for the next recipient). Links
     * to any other host (Stripe, the API, a file store, an external site) are returned unchanged.
     */
    public static function rebase(?string $url, User|int|string|array|null $recipient): ?string
    {
        if ($url === null || $url === '') return $url;

        $from = self::appBaseOf($url);
        if ($from === null) return $url;

        return self::join(self::baseFor($recipient), substr($url, strlen($from)));
    }

    /** The part of $url that is the app's own address (main site or an existing workspace), or null. */
    private static function appBaseOf(string $url): ?string
    {
        $main = self::main();
        if ($main !== '' && self::startsWithBase($url, $main)) return substr($url, 0, strlen($main));

        $pattern = rtrim(trim((string) config('app.workspace_url_pattern')), '/');
        if ($pattern === '' || ! str_contains($pattern, '{slug}')) return null;

        $regex = '#^' . str_replace(preg_quote('{slug}', '#'), '([a-z0-9-]{1,100})', preg_quote($pattern, '#')) . '(?=$|[/?\#])#i';
        if (! preg_match($regex, $url, $match)) return null;

        // Only a real workspace's address: api.tujitume.com or files.tujitume.com are never rewritten
        $slug = WorkspaceDirectory::normalize($match[1]);
        if ($slug === null || in_array($slug, WorkspaceDirectory::RESERVED_SLUGS, true)
            || ! Workspace::where('slug', $slug)->exists()) {
            return null;
        }

        return $match[0];
    }

    /**
     * The workspace whose address this person's links use, or null for the main site: they are one of the
     * organisation's own people and its workspace is in a state that is allowed to receive links.
     */
    public static function workspaceFor(User|int|string|null $recipient): ?Workspace
    {
        $user = self::user($recipient);
        if (! $user || ! $user->organization_id || ! in_array((int) $user->user_type_id, self::WORKSPACE_USER_TYPES, true)) {
            return null;
        }

        $statuses = self::linkStatuses();
        if ($statuses === []) return null;

        return Workspace::where('organization_id', $user->organization_id)
            ->whereIn('workspace_status', $statuses)
            ->orderBy('id')
            ->get()
            ->first(fn (Workspace $workspace) => self::workspaceBase($workspace) !== null);
    }

    /**
     * The workspace's own address, or null when it cannot have one: no pattern configured, or a slug that is
     * not a valid workspace name or is one of the platform's own addresses (www, api, ...).
     */
    public static function workspaceBase(Workspace $workspace): ?string
    {
        return self::forSlug($workspace->slug);
    }

    /** The address a workspace name has under WORKSPACE_URL_PATTERN, or null when it cannot be one. */
    public static function forSlug(?string $slug): ?string
    {
        $pattern = trim((string) config('app.workspace_url_pattern'));
        if ($pattern === '' || ! str_contains($pattern, '{slug}')) return null;

        $slug = WorkspaceDirectory::normalize($slug);
        if ($slug === null || in_array($slug, WorkspaceDirectory::RESERVED_SLUGS, true)) return null;

        return rtrim(str_replace('{slug}', $slug, $pattern), '/');
    }

    /** @return list<string> */
    private static function linkStatuses(): array
    {
        $statuses = config('app.workspace_link_statuses', ['active', 'pending_verification']);
        if (is_string($statuses)) $statuses = explode(',', $statuses);

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) $statuses)));
    }

    private static function user(User|int|string|null $recipient): ?User
    {
        if ($recipient instanceof User) return $recipient;
        if (is_int($recipient) || (is_string($recipient) && ctype_digit($recipient))) return User::find((int) $recipient);
        if (is_string($recipient) && str_contains($recipient, '@')) return User::where('email', trim($recipient))->first();

        return null;
    }

    private static function startsWithBase(string $url, string $base): bool
    {
        if (! str_starts_with(strtolower($url), strtolower($base))) return false;
        $next = substr($url, strlen($base), 1);

        return $next === '' || in_array($next, ['/', '?', '#'], true);
    }

    private static function join(string $base, string $path): string
    {
        if ($path === '' || $path === '/') return $base . '/';
        if ($path[0] === '?' || $path[0] === '#') return $base . '/' . $path;

        return $base . '/' . ltrim($path, '/');
    }
}
