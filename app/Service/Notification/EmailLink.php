<?php

namespace App\Service\Notification;

/**
 * The button in an email opens the same place the in-app notification does.
 *
 * Emails carry the notification's link key (dot-notation route name, e.g.
 * "dashboard.programOrg.programDealroomDetail::61::funding-setup#milestone-2"), wrapped in
 * the frontend's /go route. The app signs the visitor in first when needed, then resolves
 * the key for their role and lands them on the exact page, highlighting the right section.
 */
class EmailLink
{
    public const GO_PATH = 'go';

    /** Absolute URL for a link key, or null when there is nowhere to send the reader. */
    public static function url(?string $linkKey): ?string
    {
        $linkKey = trim((string) $linkKey);
        if ($linkKey === '') return null;

        return rtrim((string) config('app.app_url'), '/') . '/' . self::GO_PATH . '?to=' . rawurlencode($linkKey);
    }

    /**
     * Older emails sent through EmailService carry no link of their own, so they get a
     * sensible destination per view. Every key here is one the frontend resolver already
     * maps per role (see LEGACY in notificationLinkResolver.js).
     *
     * Views with their own action buttons (pay, confirm, release ...) are not listed;
     * their buttons are the point of the email.
     *
     * @return array{key: string, label: string}|null
     */
    public static function forView(string $view): ?array
    {
        $view = str_replace('/', '.', $view);

        return match (true) {
            $view === 'kyc.verified'                         => ['key' => 'overview/account', 'label' => 'Open My Account'],
            $view === 'dispute_mail'                         => ['key' => 'milestones', 'label' => 'View Dispute'],
            str_starts_with($view, 'milestone.')             => ['key' => 'milestones', 'label' => 'View Milestone'],
            in_array($view, ['opportunities.capital_pitch', 'opportunities.program_pitch'], true)
                                                             => ['key' => 'overview/pitch', 'label' => 'Review Pitch'],
            in_array($view, ['opportunities.capital_milestone', 'opportunities.program_milestone'], true)
                                                             => ['key' => 'milestones', 'label' => 'View Milestone'],
            in_array($view, ['bids.cancelled', 'bids.rejected', 'bids.under_review', 'bids.mile_fulfill'], true)
                                                             => ['key' => 'investment-bids', 'label' => 'View My Bids'],
            $view === 'bids.manager_eqp_alert'               => ['key' => 'milestones', 'label' => 'View Milestone'],
            $view === 'bids.owner_manager_alert'             => ['key' => 'dealroom', 'label' => 'Open Deal Room'],
            in_array($view, ['milestoneS.milestone_review', 'milestoneS.milestone_due_mail', 'milestoneS.provider_greeting_mail',
                             'services.booking_cancelled', 'services.under_review'], true)
                                                             => ['key' => 'mybookings', 'label' => 'View My Bookings'],
            default                                          => null,
        };
    }
}
