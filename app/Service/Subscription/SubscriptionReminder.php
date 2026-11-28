<?php

namespace App\Service\Subscription;

use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Organizations\Organization;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\EmailLink;
use App\Service\Notification\EmailService;
use Carbon\Carbon;

/**
 * "Your plan ends soon": once per subscription, DAYS_BEFORE its end, to whoever holds it (an organisation's owner,
 * or the person). Not sent when a later plan is already running (renewed), nor for a 7-day demo (it has its own).
 * For a program organisation it says that creating and publishing programs pauses when the plan ends (SubscriptionAccess).
 */
class SubscriptionReminder
{
    public const DAYS_BEFORE = 3;

    public const VIEW = 'subscriptions.ending';

    private const PAGES = [
        1 => 'dashboard.entrepreneur.subscription',
        2 => 'dashboard.investor.subscription',
        3 => 'dashboard.serviceProvider.subscription',
        4 => 'dashboard.grantOrg.subscription',
    ];

    public function __construct(private EmailService $email = new EmailService())
    {
    }

    /** @return int how many reminders went out */
    public function run(): int
    {
        $sent = 0;

        BusinessSubscriptions::where('active', 1)
            ->whereNull('ending_reminder_sent_at')
            ->where('expire_date', '>', now())
            ->where('expire_date', '<=', now()->addDays(self::DAYS_BEFORE))
            ->orderBy('id')
            ->get()
            ->each(function (BusinessSubscriptions $sub) use (&$sent) {
                // Claimed first, so two runs never both send it
                $claimed = BusinessSubscriptions::whereKey($sub->id)->whereNull('ending_reminder_sent_at')
                    ->update(['ending_reminder_sent_at' => now()]);
                if (! $claimed) return;

                if ($this->send($sub)) $sent++;
            });

        return $sent;
    }

    private function send(BusinessSubscriptions $sub): bool
    {
        $holder = User::find($sub->investor_id);
        if (! $holder || ! str_contains((string) $holder->email, '@') || str_ends_with((string) $holder->email, '@trial.invalid')) return false;

        // A demo account's trial plan: the demo has its own "ends soon" email
        if ($holder->organization_id && Organization::whereKey($holder->organization_id)->where('is_trial', true)->exists()) return false;

        // Already renewed: a later plan carries on after this one
        $renewed = BusinessSubscriptions::where('investor_id', $sub->investor_id)->where('active', 1)
            ->whereKeyNot($sub->id)->where('expire_date', '>', $sub->expire_date)->exists();
        if ($renewed) return false;

        $zone = UserSetting::where('user_id', $holder->id)->value('timezone') ?: 'Africa/Nairobi';
        $ends = Carbon::parse($sub->expire_date)->setTimezone($zone)->format('D j M Y, H:i').' ('.$zone.')';
        $isOrganisation = (int) $holder->user_type_id === 4;
        $plan = ucfirst((string) $sub->plan);

        return $this->email->send(
            ((int) $sub->trial === 1 ? 'Your free trial' : 'Your '.$plan.' plan').' ends on '.Carbon::parse($sub->expire_date)->setTimezone($zone)->format('j M'),
            self::VIEW,
            [
                // From Tujitume about Tujitume's plan, whatever the organisation's own brand
                'brand' => EmailBrand::defaults(),
                'first_name' => $holder->first_name ?: 'there',
                'plan' => (int) $sub->trial === 1 ? 'free trial' : $plan.' plan',
                'ends' => $ends,
                'consequence' => $isOrganisation
                    ? 'When it ends, your team can no longer create, import or publish programs until you renew. Everything you have stays as it is.'
                    : 'Renew before then to keep your plan\'s benefits without a break.',
                'renew_href' => EmailLink::url(self::PAGES[(int) $holder->user_type_id] ?? 'home', $holder),
            ],
            $holder->email,
        );
    }
}
