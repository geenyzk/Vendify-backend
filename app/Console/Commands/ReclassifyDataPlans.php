<?php

namespace App\Console\Commands;

use App\Models\DataPlan;
use App\Services\DataPlanCategoryClassifier;
use App\Support\PerformanceCache;
use Illuminate\Console\Command;

class ReclassifyDataPlans extends Command
{
    protected $signature = 'data-plans:reclassify {--provider= : Limit to a provider id}';
    protected $description = 'Recalculate automatic merchandising categories without changing manual overrides';

    public function handle(DataPlanCategoryClassifier $classifier): int
    {
        $query = DataPlan::query()->with('providers');
        if ($providerId = $this->option('provider')) {
            $query->whereHas('providers', fn ($query) => $query->whereKey($providerId));
        }

        $summary = ['classified' => 0, 'manual_overrides_preserved' => 0, 'special' => 0];
        $query->chunkById(200, function ($plans) use ($classifier, &$summary) {
            foreach ($plans as $plan) {
                $providerPlanName = (string) ($plan->providers->first()?->pivot?->provider_plan_name ?? '');
                $result = $classifier->classify($providerPlanName ?: $plan->plan, (string) $plan->validity, (string) $plan->plan_type);
                $plan->update(['auto_category_id' => $classifier->categoryId($providerPlanName ?: $plan->plan, (string) $plan->validity, (string) $plan->plan_type)]);
                $summary['classified']++;
                $summary['manual_overrides_preserved'] += $plan->manual_category_id ? 1 : 0;
                $summary['special'] += $result['slug'] === 'special' ? 1 : 0;
            }
        });

        PerformanceCache::clearCatalog();
        $this->table(['classified', 'manual overrides preserved', 'special/unclassified'], [[
            $summary['classified'], $summary['manual_overrides_preserved'], $summary['special'],
        ]]);
        return self::SUCCESS;
    }
}
