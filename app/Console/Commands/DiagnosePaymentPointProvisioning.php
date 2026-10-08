<?php

namespace App\Console\Commands;

use App\Classes\Payment\PaymentFactory;
use App\Models\Bank;
use App\Models\Provider;
use App\Models\ServiceControl;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnosePaymentPointProvisioning extends Command
{
    protected $signature = 'paymentpoint:diagnose {--user= : Optional authorised user ID to inspect without provisioning}';

    protected $description = 'Read-only PaymentPoint provisioning diagnostics with credentials and account details redacted';

    public function handle(): int
    {
        $migration = '2026_10_08_230000_repair_payment_point_service_control';
        $providers = Provider::query()
            ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])
            ->get();
        $controls = ServiceControl::query()
            ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])
            ->get();

        $report = [
            'environment' => app()->environment(),
            'cache_store' => config('cache.default'),
            'cache_lock_table_present' => config('cache.default') !== 'database'
                || Schema::hasTable((string) (config('cache.stores.database.lock_table') ?: 'cache_locks')),
            'repair_migration_applied' => Schema::hasTable('migrations')
                && DB::table('migrations')->where('migration', $migration)->exists(),
            'bank_unique_index_present' => Schema::hasTable('banks')
                && Schema::hasIndex('banks', 'banks_user_provider_unique'),
            'providers' => $providers->map(fn (Provider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'active' => (bool) $provider->active,
                'missing_create_account_fields' => PaymentFactory::missingRequiredCredentials($provider),
                'missing_funding_fields' => PaymentFactory::missingFundingConfiguration($provider),
            ])->values()->all(),
            'service_controls' => $controls->map(fn (ServiceControl $control) => [
                'id' => $control->id,
                'name' => $control->name,
                'active' => (bool) $control->isActive,
                'dev_locked' => (bool) $control->isDevLock,
            ])->values()->all(),
            'eligible_provider_ids' => Provider::query()
                ->getPaymentProviders()
                ->pluck('id')
                ->values()
                ->all(),
        ];

        if ($userId = $this->option('user')) {
            $user = User::query()->find($userId);
            $report['user'] = $user ? [
                'id' => $user->id,
                'has_email' => trim((string) $user->email) !== '',
                'has_name' => trim((string) ($user->fullname ?: $user->username)) !== '',
                'has_phone' => trim((string) $user->phone) !== '',
                'usable_accounts' => Bank::query()
                    ->where('user_id', $user->id)
                    ->withUsableAccountDetails()
                    ->count(),
            ] : ['id' => $userId, 'found' => false];
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $healthy = $report['repair_migration_applied']
            && $report['cache_lock_table_present']
            && count($report['providers']) === 1
            && count($report['service_controls']) === 1
            && $report['providers'][0]['active']
            && $report['providers'][0]['missing_funding_fields'] === []
            && $report['service_controls'][0]['active']
            && ! $report['service_controls'][0]['dev_locked']
            && in_array($report['providers'][0]['id'], $report['eligible_provider_ids'], true);

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
