<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PerformanceCache
{
    public const ADMIN_STATS_KEY = 'admin:stats:v1';
    public const BRANDING_KEY = 'public:branding:v1';
    public const SYSTEM_INFO_KEY = 'admin:system-info:v1';

    public static function analyticsKey(string $startDate, string $endDate): string
    {
        $version = (int) Cache::get('admin:analytics:v1:version', 1);

        return "admin:analytics:v1:{$version}:{$startDate}:{$endDate}";
    }

    public static function catalogKey(string $table, array $query): string
    {
        ksort($query);

        return 'catalog:v1:' . $table . ':' . md5(json_encode($query));
    }

    public static function clearDashboard(): void
    {
        // A busy installation writes transactions continuously. Invalidating
        // on every write meant the supposedly cached admin dashboard was
        // almost always cold and repeatedly ran all aggregate queries.
        // One invalidation per minute matches the stats TTL and bounds
        // staleness while allowing the cache to do useful work.
        if (!app()->environment('testing')
            && !Cache::add('admin:dashboard:invalidation-lock', 1, now()->addMinute())) {
            return;
        }

        Cache::forget(self::ADMIN_STATS_KEY);
        self::clearAnalytics();
    }

    public static function clearAnalytics(): void
    {
        $version = (int) Cache::get('admin:analytics:v1:version', 1);
        Cache::forever('admin:analytics:v1:version', $version + 1);
    }

    public static function clearBranding(): void
    {
        Cache::forget(self::BRANDING_KEY);
        Cache::forget(self::SYSTEM_INFO_KEY);
    }

    public static function clearCatalog(): void
    {
        $version = (int) Cache::get('catalog:v1:version', 1);
        Cache::forever('catalog:v1:version', $version + 1);
    }

    public static function catalogVersionedKey(string $table, array $query): string
    {
        $version = (int) Cache::get('catalog:v1:version', 1);

        return self::catalogKey($table, array_merge($query, ['_v' => $version]));
    }
}
