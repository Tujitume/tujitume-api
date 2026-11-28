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

    /**
     * Absolute URL for a link key, or null when there is nowhere to send the reader. It opens on the
     * recipient's own address: their organisation's workspace when they work inside one, else the main
     * site (see RecipientUrl). Without a recipient it is the main site.
     */
    public static function url(?string $linkKey, \App\Models\Auth\User|int|string|array|null $recipient = null): ?string
    {
        $linkKey = trim((string) $linkKey);
        if ($linkKey === '') return null;

        // C6: a service provider's email never opens the Business Coach area
        if (str_starts_with($linkKey, 'dashboard.reviewerGrantOrg') && $recipient !== null && ! is_array($recipient)) {
            $type = $recipient instanceof \App\Models\Auth\User ? $recipient->user_type_id
                : (is_numeric($recipient) ? \App\Models\Auth\User::whereKey((int) $recipient)->value('user_type_id') : null);
            if ((int) $type === 3) $linkKey = \App\Models\Communication\Notifications::providerLink($linkKey);
        }

        return RecipientUrl::url(self::GO_PATH . '?to=' . rawurlencode($linkKey), $recipient);
    }

    /** The bookings page of whoever made a booking (investors, capital and program organisations each have their own). */
    public static function bookingsFor($userId): string
    {
        return match ((int) \App\Models\Auth\User::whereKey($userId)->value('user_type_id')) {
            1 => 'dashboard.entrepreneur.bookings',
            3 => 'dashboard.serviceProvider.myBookings',
            4 => 'dashboard.programOrg.bookings',
            5 => 'dashboard.capitalOrg.bookings',
            default => 'dashboard.investor.bookings',
        };
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
