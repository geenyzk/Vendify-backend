<?php

namespace App\Services\AiManager\Tools;

use App\Http\Controllers\ServiceRoutingController;
use App\Models\User;

/**
 * Which upstream vendor each service/route currently points to. Reuses the
 * admin Service Routing screen's live enumeration so a brand-new
 * network/category/disco shows up without any schema change.
 */
class GetServiceRoutingTool extends AiTool
{
    public function name(): string
    {
        return 'get_service_routing';
    }

    public function description(): string
    {
        return 'Show current service routing: the available vendors and, for each service (data, airtime, cable, electricity, exam) and route key, which vendor/provider it is assigned to (null = default fallback). Use this before proposing a routing change with update_service_routing.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        return app(ServiceRoutingController::class)->index()->getData(true)['data'] ?? [];
    }
}
