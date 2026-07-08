<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Reset links must land on the SPA (vtu_2's /auth/reset-password
        // reads ?token=&email=), not this API's blade route — without this
        // the default notification links users to a page that can't help
        // them. Same URL shape as MigratedAccountInvite.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')
                . '/auth/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
