<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BulkEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;
    public $subject;
    public $view;

    public function __construct(string $subject, string $view, array $data)
    {
        $this->data = $data;
        $this->subject = $subject;
        $this->view = $view;
    }

    public function build()
    {
        return $this->subject($this->subject)
            ->view($this->view)
            ->with($this->data);
    }
}
