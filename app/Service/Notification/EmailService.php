<?php
namespace App\Service\Notification;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\Mail;

class EmailService
{

    public function send(string $subject, string $view, $data, $email)
    {
        try{
            Mail::send($view, $data, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
        }

    }


}
