<?php

namespace App\Listeners;

use App\Service\Notification\EmailBrand;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * An email that was not given a sender name of its own goes out under the recipient's organisation
 * (when they belong to one) instead of "Tujitume". The sending address never changes, so
 * SPF/DKIM keep passing. Emails whose sender was set explicitly (see EmailService) are left alone.
 */
class BrandMailSender
{
    public function handle(MessageSending $event): void
    {
        $message  = $event->message;
        $defaults = config('mail.from');
        $from     = $message->getFrom()[0] ?? null;

        // Only touch mail still wearing the app-wide default sender
        if ($from && $from->getName() !== '' && $from->getName() !== ($defaults['name'] ?? null)) return;

        $to = $message->getTo()[0] ?? null;
        if (!$to) return;

        $brand = EmailBrand::forEmail($to->getAddress());
        if (empty($brand['custom'])) return;

        $message->from(new Address($from?->getAddress() ?: $defaults['address'], $brand['name']));
    }
}
