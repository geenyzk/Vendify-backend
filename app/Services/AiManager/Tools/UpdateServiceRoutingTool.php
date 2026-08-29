<?php

namespace App\Services\AiManager\Tools;

use App\Http\Controllers\ServiceRoutingController;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AiManager\Tools\Concerns\CallsControllerAction;
use Illuminate\Http\Request;

/**
 * Point a service/route at a specific vendor (or clear it to fall back to the
 * default). Mutating: proposal-only, admin-level. Delegates to
 * ServiceRoutingController::update so the persisted service_routes rows stay
 * exactly what the routing screen writes.
 */
class UpdateServiceRoutingTool extends AiTool
{
    use CallsControllerAction;

    public function name(): string
    {
        return 'update_service_routing';
    }

    public function description(): string
    {
        return 'Assign a service route to an upstream vendor, or clear it (provider_id null) to use the default fallback. Inspect the exact service_type and route_key with get_service_routing first. Creates a pending action an admin must approve.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service_type' => ['type' => 'string', 'description' => 'One of: data, cable, electricity, exam. Airtime is configured on its plan.'],
                'route_key' => ['type' => 'string', 'description' => 'The specific route key within the service (e.g. a network, plan type, cable network, or disco). Use the exact key from get_service_routing.'],
                'provider_id' => ['type' => 'integer', 'description' => 'Vendor/provider id to route to, or null to clear to default.'],
            ],
            'required' => ['service_type', 'route_key'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'service_type' => 'required|string|max:40',
            'route_key' => 'required|string|max:120',
            'provider_id' => 'nullable|integer|exists:providers,id',
        ];
    }

    public function summarize(array $arguments): string
    {
        $target = isset($arguments['provider_id']) && $arguments['provider_id']
            ? ('vendor ' . (Vendor::find($arguments['provider_id'])?->name ?? "#{$arguments['provider_id']}"))
            : 'the default fallback';

        return "Route {$arguments['service_type']} / {$arguments['route_key']} to {$target}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $request = Request::create('/', 'PUT', [
            'routes' => [[
                'service_type' => $arguments['service_type'],
                'route_key' => $arguments['route_key'],
                'provider_id' => $arguments['provider_id'] ?? null,
            ]],
        ]);

        $this->unwrap(app(ServiceRoutingController::class)->update($request), 'Routing could not be updated.');

        return [
            'updated' => true,
            'service_type' => $arguments['service_type'],
            'route_key' => $arguments['route_key'],
            'provider_id' => $arguments['provider_id'] ?? null,
        ];
    }
}
