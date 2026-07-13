<?php

namespace App\Mail;

use App\Support\MailDeliverability;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestSesMail extends Mailable
{
    use SerializesModels;

    public function build()
    {
        $viewData = [
            'preheader' => 'Your Vendify SMTP test email was sent.',
            'heading' => 'Email delivery is ready',
            'intro' => 'This is a safe test email from the Vendify Laravel backend.',
            'body' => 'If this arrived in your inbox, Amazon SES SMTP is configured correctly for this environment.',
            'footerNote' => 'This message was triggered manually by a developer using an Artisan command.',
        ];

        return $this->subject('Vendify test email')
            ->view('emails.base', $viewData)
            ->text('emails.plain', $viewData)
            ->withSymfonyMessage(fn ($message) => MailDeliverability::apply($message, 'test'));
    }
}
