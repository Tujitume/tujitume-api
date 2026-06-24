<?php
namespace App\Service\Notification;
use App\Jobs\SendBulkEmailJob;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class BulkEmailService
{
    protected array $errors = [];
    protected array $emails = [];

    public function extractEmails(string $filePath, string $type = 'csv'): array
    {
        $lines = [];

        if ($type === 'csv') {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } elseif ($type === 'excel') {
            $imported = Excel::toArray([], $filePath);
            $lines = $imported[0] ?? []; // get first sheet
        }

        if (!empty($lines)) {
            $firstRowEmail = is_array($lines[0]) ? $lines[0][0] : $lines[0];
            if (!str_contains($firstRowEmail, '@')) {
                array_shift($lines); // skip header
            }
        }

        foreach ($lines as $index => $row) {
            $email = is_array($row) ? $row[0] : $row; // assume first column contains email
            $email = trim($email);

            $validator = Validator::make(['email' => $email], [
                'email' => 'required|email|max:255'
            ]);

            if ($validator->fails()) {
                $this->errors[$index] = $validator->errors()->all();
                continue;
            }

            $this->emails[] = $email;
        }

        return [
            'valid_emails' => $this->emails,
            'errors' => $this->errors
        ];
    }

    public function send(string $subject, string $view, array $data = [], array $emails)
    {
//        foreach ($emails as $email) {
//            Mail::send($view, $data, function ($message) use ($email, $subject) {
//                $message->to($email)->subject($subject);
//            });
//        }
//
        //QUEUE
        foreach ($emails as $email) {
            SendBulkEmailJob::dispatch($email, $subject, $view, $data);
        }
    }


}
