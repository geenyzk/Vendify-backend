<?php

namespace App\Console\Commands;

use App\Mail\TestSesMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendTestEmail extends Command
{
    protected $signature = 'mail:test-ses {email : Recipient email address}';

    protected $description = 'Send a safe Vendify test email through the configured mailer.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid recipient email address.');
            return self::FAILURE;
        }

        try {
            Mail::to($email)->send(new TestSesMail());
            $this->info('Test email sent.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::warning('Failed to send SES SMTP test email', [
                'recipient_hash' => hash('sha256', strtolower($email)),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->error('We could not send the email. Please check the mail configuration and try again.');
            return self::FAILURE;
        }
    }
}
