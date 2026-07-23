<?php

namespace App\Providers;

use App\Interfaces\UserRepositoryInterface;
use App\Models\AirtimePlan;
use App\Models\BillPlan;
use App\Models\CablePlan;
use App\Models\ChildCustomer;
use App\Models\ChildInstance;
use App\Models\ChildTransaction;
use App\Models\DataPlan;
use App\Models\DiscoProviderId;
use App\Models\Discount;
use App\Models\General;
use App\Models\Network;
use App\Models\NetworkType;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MailSettingsService;
use App\Services\AiManager\VendifyDataPlanBrowser;
use App\Services\AiManager\Tools\ToolRegistry;
use App\Support\PerformanceCache;
use App\Repository\Admin\UserRepository;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Explicit bindings make browser/tool availability deterministic in
        // HTTP, queue and Octane lifecycles. scoped() rebuilds the registry for
        // each request/job so a restarted process reads the active config.
        $this->app->singleton(VendifyDataPlanBrowser::class);
        $this->app->scoped(ToolRegistry::class, fn () => new ToolRegistry());

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyDatabaseMailConfig();
        $this->registerPerformanceCacheInvalidation();
        $this->registerSecurityRateLimiters();

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

    protected function registerSecurityRateLimiters(): void
    {
        RateLimiter::for('auth.refresh', fn (Request $request) => [
            Limit::perMinute(12)->by('refresh|' . $request->ip()),
        ]);
        RateLimiter::for('session.extend', fn (Request $request) => [
            Limit::perMinute(6)->by('extend|' . ($request->user()?->id ?: $request->ip())),
        ]);
        RateLimiter::for('sensitive-auth', fn (Request $request) => [
            Limit::perMinute(5)->by('sensitive|' . ($request->user()?->id ?: $request->ip())),
            Limit::perMinute(20)->by('sensitive-ip|' . $request->ip()),
        ]);
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
            // Login and every API request boot the application. Avoid a
            // schema metadata query on every request too. If the table is not
            // deployed yet, the guarded cache callback throws into this
            // method's existing fallback and .env mail config remains active.
            $settings = Cache::remember('runtime_mail_settings', now()->addMinute(), fn () => Setting::first());
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
                Config::set('mail.mailers.smtp.scheme', MailSettingsService::normalizeSmtpScheme($settings->mail_encryption));
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

    protected function registerPerformanceCacheInvalidation(): void
    {
        foreach ([Transaction::class, User::class, ChildInstance::class, ChildCustomer::class, ChildTransaction::class] as $model) {
            $model::saved(fn () => PerformanceCache::clearDashboard());
            $model::deleted(fn () => PerformanceCache::clearDashboard());
        }

        foreach ([General::class, Setting::class] as $model) {
            $model::saved(fn () => PerformanceCache::clearBranding());
            $model::deleted(fn () => PerformanceCache::clearBranding());
        }

        Setting::saved(fn () => Cache::forget('runtime_mail_settings'));
        Setting::deleted(fn () => Cache::forget('runtime_mail_settings'));

        foreach ([Network::class, NetworkType::class, DataPlan::class, AirtimePlan::class, CablePlan::class, BillPlan::class, DiscoProviderId::class, Discount::class] as $model) {
            $model::saved(fn () => PerformanceCache::clearCatalog());
            $model::deleted(fn () => PerformanceCache::clearCatalog());
        }
    }
}
