<?php

namespace App\Services\AiManager\Tools;

use App\Models\ChildInstance;
use App\Models\User;

/**
 * Read-only list of affiliate (child) instances with their health and
 * registration state. Never exposes the shared secret / registration code
 * (those are $hidden and only reachable via the dedicated admin endpoints).
 */
class ListAffiliatesTool extends AiTool
{
    public function name(): string
    {
        return 'list_affiliates';
    }

    public function description(): string
    {
        return 'List affiliate (child) instances connected to the platform, with their status, health, last-seen time, and customer count. Use to check whether an affiliate is online before sending it a directive.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function handle(array $arguments, User $actor): array
    {
        $affiliates = ChildInstance::query()
            ->withCount('customers')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'base_url', 'status', 'health_status', 'last_seen_at', 'registered_at'])
            ->map(fn (ChildInstance $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'base_url' => $c->base_url,
                'status' => $c->status,
                'health_status' => $c->health_status,
                'customers' => $c->customers_count,
                'last_seen_at' => optional($c->last_seen_at)->toDateTimeString(),
                'registered_at' => optional($c->registered_at)->toDateTimeString(),
            ]);

        return ['affiliates' => $affiliates];
    }
}
