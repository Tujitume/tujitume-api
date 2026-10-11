<?php

namespace App\Service\Organization;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\EmailService;
use App\Service\Notification\NotificationService;

/**
 * Tells a team member (internal or external reviewer) about a change to their access: an email, and an
 * in-app notification unless their account no longer exists. Best effort: it never fails the action.
 */
class TeamMemberNotifier
{
    /**
     * @param string $action removed | revoked | reinvited | role_changed
     */
    public static function notify(
        User $member,
        ?User $actor,
        string $action,
        string $roleName,
        ?string $invitationUrl = null,
        bool $notify = true,
    ): void {
        try {
            $organization = Organization::find($actor?->organization_id ?? $member->organization_id);
            if (! $organization || ! $member->email) {
                return;
            }

            $orgName = $organization->name;
            $roleLabel = str_replace('_', ' ', $roleName);

            [$title, $body, $inAppText] = match ($action) {
                'removed' => [
                    "You were removed from {$orgName}",
                    "You have been removed from {$orgName} and no longer have access to its workspace.",
                    "You were removed from {$orgName}.",
                ],
                'revoked' => [
                    "Your access to {$orgName} was deactivated",
                    "Your access to {$orgName} has been deactivated. You can no longer use its workspace until it is restored.",
                    "Your access to {$orgName} was deactivated.",
                ],
                'activated' => [
                    "Your access to {$orgName} was restored",
                    "Your access to {$orgName} has been restored. You can sign in and use its workspace again.",
                    "Your access to {$orgName} was restored.",
                ],
                'role_changed' => [
                    "Your role in {$orgName} changed",
                    "Your role in {$orgName} is now {$roleLabel}.",
                    "Your role in {$orgName} is now {$roleLabel}.",
                ],
                default => [
                    "You have been invited back to {$orgName}",
                    "{$orgName} has reopened your invitation. Accept it to get access to the workspace again.",
                    "{$orgName} reopened your invitation.",
                ],
            };

            (new EmailService())->send(
                $title,
                'organization_team_update',
                [
                    'brand' => EmailBrand::forOrganization($organization),
                    'title' => $title,
                    'body' => $body,
                    'recipientName' => $member->first_name ?: ($member->display_name ?: 'there'),
                    'organization' => $organization,
                    'role' => $roleLabel,
                    'actorName' => trim(($actor?->first_name ?? '') . ' ' . ($actor?->last_name ?? '')) ?: $orgName,
                    'invitationUrl' => $invitationUrl,
                    'expiresAt' => now()->addDays(7),
                ],
                $member->email
            );

            if ($notify && $member->exists) {
                (new NotificationService())->create($member->id, $actor?->id ?? $member->id, $inAppText, '', 'program');
            }
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['team_member_id' => $member->id, 'action' => $action]);
        }
    }
}
