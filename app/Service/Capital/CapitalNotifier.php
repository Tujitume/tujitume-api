<?php

namespace App\Service\Capital;

use App\Models\Auth\User;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\EmailLink;
use App\Service\Notification\NotificationService;
use Illuminate\Support\Facades\Mail;

/**
 * Capital step notifications: an in-app notification (link = a route key the app resolves, see
 * notificationLinkResolver.js) and, when a view is given, the matching email whose button opens the same place.
 * A failed email never fails the step.
 *
 * Link keys used (id values are "@<id>", obfuscated by the app):
 *   dashboard.capitalOrg.pitch?pitch=@<pitch_id>                        provider: a new application to review
 *   dashboard.capitalOrg.investments?pitch=@<pitch_id>&tab=terms        provider: counter-offer / terms answered
 *   dashboard.capitalOrg.investments?pitch=@<pitch_id>&tab=funding      provider: the entrepreneur asks for the next tranche
 *   dashboard.entrepreneur.capitalDealroom?pitch=@<pitch_id>            entrepreneur: application accepted
 *   dashboard.entrepreneur.capitalDealroom?pitch=@<pitch_id>&tab=terms  entrepreneur: terms proposed / counter answered
 *   dashboard.entrepreneur.capitalDealroom?pitch=@<pitch_id>&tab=funding entrepreneur: a tranche was released
 *   dashboard.entrepreneur.capitalApplication?application=@<pitch_id>   entrepreneur: application not selected
 */
class CapitalNotifier
{
    public static function providerPitch(int $pitchId): string
    {
        return 'dashboard.capitalOrg.pitch?pitch=@'.$pitchId;
    }

    public static function providerDeal(int $pitchId, string $tab): string
    {
        return 'dashboard.capitalOrg.investments?pitch=@'.$pitchId.'&tab='.$tab;
    }

    public static function applicantDeal(int $pitchId, ?string $tab = null): string
    {
        return 'dashboard.entrepreneur.capitalDealroom?pitch=@'.$pitchId.($tab ? '&tab='.$tab : '');
    }

    public static function applicantApplication(int $pitchId): string
    {
        return 'dashboard.entrepreneur.capitalApplication?application=@'.$pitchId;
    }

    /**
     * @param  int[]  $recipientIds
     */
    public static function send(array $recipientIds, int $fromId, string $text, string $link, ?string $view = null, array $mailData = [], string $subject = 'Tujitume'): void
    {
        $notifications = new NotificationService();
        foreach (array_values(array_unique(array_map('intval', $recipientIds))) as $id) {
            try {
                $notifications->create($id, $fromId, $text, $link, 'capital');
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['capital_notification' => $link, 'receiver_id' => $id]);
            }
            if (! $view) {
                continue;
            }
            try {
                $user = User::find($id);
                if (! $user?->email) {
                    continue;
                }
                $data = $mailData + ['action_url' => EmailLink::url($link, $user), 'action_label' => 'Open in Tujitume'];
                Mail::send($view, $data, function ($msg) use ($user, $subject) {
                    $msg->to($user->email);
                    $msg->subject($subject);
                });
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['capital_email' => $view, 'receiver_id' => $id]);
            }
        }
    }
}
