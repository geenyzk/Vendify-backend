<?php

namespace App\Services\AiManager\Tools;

use App\Models\Discount;
use App\Models\Promotion;
use App\Models\User;

/**
 * Read-only overview of current marketing levers — active discounts and
 * promotions — so the assistant can answer "what offers are running?" and
 * reference existing campaigns before proposing new ones.
 */
class ListMarketingTool extends AiTool
{
    public function name(): string
    {
        return 'list_marketing';
    }

    public function description(): string
    {
        return 'List the platform\'s current discounts and promotions (promo codes and auto promotions), including whether each is active and its window. Use before creating a new discount or promotion to avoid duplicates.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'active_only' => ['type' => 'boolean', 'description' => 'Only currently-active entries. Default false.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['active_only' => 'nullable|boolean'];
    }

    public function handle(array $arguments, User $actor): array
    {
        $activeOnly = (bool) ($arguments['active_only'] ?? false);

        $discounts = Discount::query()
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->latest('id')->limit(50)
            ->get(['id', 'name', 'service_type', 'network', 'discount_type', 'value', 'active', 'starts_at', 'ends_at'])
            ->map(fn (Discount $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'service_type' => $d->service_type,
                'network' => $d->network,
                'type' => $d->discount_type,
                'value' => (float) $d->value,
                'active' => (bool) $d->active,
                'starts_at' => optional($d->starts_at)->toDateString(),
                'ends_at' => optional($d->ends_at)->toDateString(),
            ]);

        $promotions = Promotion::query()
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->latest('id')->limit(50)
            ->get(['id', 'name', 'code', 'apply', 'target', 'product', 'type', 'value', 'active', 'starts_at', 'ends_at', 'usage_limit_total', 'used'])
            ->map(fn (Promotion $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'code' => $p->code,
                'apply' => $p->apply,
                'target' => $p->target,
                'product' => $p->product,
                'type' => $p->type,
                'value' => (float) $p->value,
                'active' => (bool) $p->active,
                'starts_at' => optional($p->starts_at)->toDateString(),
                'ends_at' => optional($p->ends_at)->toDateString(),
                'usage_limit_total' => $p->usage_limit_total,
                'used' => $p->used,
            ]);

        return [
            'discounts' => $discounts,
            'promotions' => $promotions,
        ];
    }
}
