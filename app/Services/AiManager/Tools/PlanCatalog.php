<?php

namespace App\Services\AiManager\Tools;

use App\Models\AirtimePinPlan;
use App\Models\AirtimePlan;
use App\Models\BillPlan;
use App\Models\CablePlan;
use App\Models\DataPinPlan;
use App\Models\DataPlan;
use App\Models\ExamPlan;
use App\Support\PerformanceCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlanCatalog
{
    public const TABLES = [
        'data_plans' => DataPlan::class,
        'airtime_plans' => AirtimePlan::class,
        'cable_plans' => CablePlan::class,
        'bill_plans' => BillPlan::class,
        'exam_plans' => ExamPlan::class,
        'airtime_pin_plans' => AirtimePinPlan::class,
        'data_pin_plans' => DataPinPlan::class,
    ];

    public static function modelClass(string $table): ?string
    {
        return self::TABLES[$table] ?? null;
    }

    public static function tableNames(): array
    {
        return array_keys(self::TABLES);
    }

    public static function columns(string $table): array
    {
        $modelClass = self::modelClass($table);
        if (!$modelClass) {
            return [];
        }

        return Schema::getColumnListing((new $modelClass())->getTable());
    }

    public static function cleanAttributes(string $table, array $attributes): array
    {
        $columns = self::columns($table);
        $blocked = ['id', 'created_at', 'updated_at'];

        return array_filter(
            $attributes,
            fn ($value, $key): bool => in_array($key, $columns, true) && !in_array($key, $blocked, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public static function syncProviderable(Model $model, array $payload): void
    {
        if (!method_exists($model, 'providers')) {
            return;
        }

        $providerable = $payload['providerable'] ?? null;
        $hasToggle = array_key_exists('use_provider_as_providerable', $payload);

        if (!$providerable && !$hasToggle) {
            return;
        }

        $providerable = is_array($providerable) ? $providerable : [];
        $providerId = ($hasToggle && $payload['use_provider_as_providerable'])
            ? ($providerable['provider_id'] ?? null)
            : null;

        $key = [
            'providerable_id' => $model->id,
            'providerable_type' => get_class($model),
        ];

        $fields = [
            'cost_price' => $providerable['cost_price'] ?? 0,
            'margin_value' => $providerable['margin_value'] ?? 0,
            'margin_type' => $providerable['margin_type'] ?? 'fiat',
            'server_id' => $providerable['server_id'] ?? $payload['server_id'] ?? null,
            'updated_at' => now(),
        ];

        // providerables.provider_id is NOT NULL, so we can never write a null
        // provider. When no provider is being assigned, only refresh the
        // cost/margin/server on an EXISTING pivot row (leaving its provider_id
        // intact); if there's no row yet, there is nothing valid to insert.
        if ($providerId === null) {
            DB::table('providerables')->where($key)->update($fields);

            return;
        }

        DB::table('providerables')->updateOrInsert(
            $key,
            $fields + ['provider_id' => $providerId, 'created_at' => now()],
        );
    }

    public static function clearCache(): void
    {
        PerformanceCache::clearCatalog();
    }
}
