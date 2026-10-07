<?php
namespace App\Service\Notification;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\Mail;

class EmailService
{

    public function send(string $subject, string $view, $data, $email)
    {
        try{
            $data = $this->withDefaults($view, (array) $data, $email);

            // The sender's display name is the brand (program owner for applicants, the recipient's own org otherwise)
            $fromName = !empty($data['brand']['custom']) ? $data['brand']['name'] : null;

            Mail::send($view, $data, function ($message) use ($email, $subject, $fromName) {
                $message->to($email)->subject($subject);
                if ($fromName) $message->from(config('mail.from.address'), $fromName);
            });
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
        }

    }

    /**
     * What every email needs and the caller may not have supplied:
     *  - brand: the recipient's own organisation (name, logo, colour), else Tujitume's look
     *  - action_url / action_label: where the email's button goes (see EmailLink)
     */
    private function withDefaults(string $view, array $data, $email): array
    {
        $data['brand'] ??= EmailBrand::forEmail($email);

        if (empty($data['action_url']) && ($target = EmailLink::forView($view))) {
            $data['action_url']   = EmailLink::url($target['key']);
            $data['action_label'] ??= $target['label'];
        }

        return $data;
    }


}
