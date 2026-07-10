<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $bodyText)
    {
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->view('emails.base')
            ->with([
                'preheader' => $this->subjectLine,
                'heading' => $this->subjectLine,
                'intro' => 'A Vendify admin notification needs your attention.',
                'body' => $this->bodyText,
                'footerNote' => 'Automated admin notification from Vendify.',
            ]);
    }
}
