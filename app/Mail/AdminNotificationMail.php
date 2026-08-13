<?php

namespace App\Mail;

use App\Support\MailDeliverability;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminNotificationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public string $emailCategory = 'transactional',
    )
    {
    }

    public function build()
    {
        $viewData = [
            'preheader' => $this->subjectLine,
            'heading' => $this->subjectLine,
            'intro' => 'A message from Vendify.',
            'body' => $this->bodyText,
            'footerNote' => 'This message was sent by Vendify.',
        ];

        return $this->subject($this->subjectLine)
            ->view('emails.base', $viewData)
            ->text('emails.plain', $viewData)
            ->withSymfonyMessage(fn ($message) => MailDeliverability::apply($message, $this->emailCategory));
    }
}
