<?php

namespace App\Providers;

use App\Interfaces\UserRepositoryInterface;
use App\Models\Setting;
use App\Repository\Admin\UserRepository;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        $this->app
            ->bind(UserRepositoryInterface::class, UserRepository::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyDatabaseMailConfig();

        // The default ResetPassword notification builds its link from the
        // named `password.reset` web route, which resolves to the Laravel
        // backend (APP_URL) — a Blade view the SPA never renders. Point it
        // at the frontend instead, matching the pattern the email
        // verification link already uses.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontendUrl = rtrim(explode(',', env('FRONTEND_URL', 'http://localhost:5173'))[0], '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    // Admin-configured mail settings (Settings > Email tab) override
    // whatever is in .env, applied fresh on every request. Left null means
    // "use whatever config/mail.php + .env already resolved to" — this is
    // opt-in per field, not a wholesale replacement, and never throws: a
    // missing settings table (e.g. mid-migration) or DB hiccup just leaves
    // the .env-driven mail config in place.
    protected function applyDatabaseMailConfig(): void
    {
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }

            $settings = Setting::first();
            if (!$settings) {
                return;
            }

            if ($settings->mail_mailer) {
                Config::set('mail.default', $settings->mail_mailer);
            }
            if ($settings->mail_host) {
                Config::set('mail.mailers.smtp.host', $settings->mail_host);
            }
            if ($settings->mail_port) {
                Config::set('mail.mailers.smtp.port', $settings->mail_port);
            }
            if ($settings->mail_username) {
                Config::set('mail.mailers.smtp.username', $settings->mail_username);
            }
            if ($settings->mail_password) {
                Config::set('mail.mailers.smtp.password', $settings->mail_password);
            }
            if ($settings->mail_encryption) {
                Config::set('mail.mailers.smtp.encryption', $settings->mail_encryption);
            }
            if ($settings->mail_from_address) {
                Config::set('mail.from.address', $settings->mail_from_address);
            }
            if ($settings->mail_from_name) {
                Config::set('mail.from.name', $settings->mail_from_name);
            }
        } catch (\Throwable $e) {
            // Fresh install / DB not reachable yet — fall back to .env silently.
        }
    }
}
