<?php

namespace App\Notifications;

use App\Service\Notification\EmailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceVerificationCode extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public string $code, public string $deviceLabel)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // Code in the subject + a plain-text part is what lets Gmail offer its "Copy code" chip
            ->subject("{$this->code} – your Tujitume device verification code")
            ->view(['device_verification_code', 'device_verification_code_text'], [
                'brand'       => EmailBrand::forUser($notifiable),
                'name'        => $notifiable->name,
                'code'        => $this->code,
                'deviceLabel' => $this->deviceLabel,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
