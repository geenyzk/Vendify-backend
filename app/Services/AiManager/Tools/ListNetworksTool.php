<?php

namespace App\Services\AiManager\Tools;

use App\Models\Network;
use App\Models\NetworkType;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Inspect the networks (MTN, GLO, Airtel, 9mobile …) and network types (the
 * per-service categories like data SME/GIFTING or cable DStv/GOtv). Grounds the
 * assistant before it proposes a manage_network change.
 */
class ListNetworksTool extends AiTool
{
    public function name(): string
    {
        return 'list_networks';
    }

    public function description(): string
    {
        return 'List networks and/or network types. "networks" are the carriers (MTN, GLO, Airtel, 9mobile) with their API ids and airtime-to-cash config; "network_types" are per-service categories (service_type = data/airtime/cable, e.g. SME, GIFTING, DStv). Use before proposing a manage_network create/update/delete.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => ['type' => 'string', 'enum' => ['networks', 'network_types', 'all'], 'description' => 'Which catalog to list. Default all.'],
                'service_type' => ['type' => 'string', 'description' => 'For network_types: filter by service_type (data, airtime, cable).'],
                'active_only' => ['type' => 'boolean', 'description' => 'Only active rows. Default false.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'target' => 'nullable|in:networks,network_types,all',
            'service_type' => 'nullable|string|max:40',
            'active_only' => 'nullable|boolean',
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $target = $arguments['target'] ?? 'all';
        $activeOnly = !empty($arguments['active_only']);
        $out = [];

        if ($target === 'networks' || $target === 'all') {
            $q = Network::query();
            if ($activeOnly) {
                $q->where('active', true);
            }
            $out['networks'] = $q->orderBy('name')->get()->map->toArray();
            $out['networks_columns'] = Schema::getColumnListing('networks');
        }

        if ($target === 'network_types' || $target === 'all') {
            $q = NetworkType::query();
            if ($activeOnly) {
                $q->where('active', true);
            }
            if (!empty($arguments['service_type'])) {
                $q->where('service_type', $arguments['service_type']);
            }
            $out['network_types'] = $q->orderBy('service_type')->orderBy('name')->get()->map->toArray();
            $out['network_types_columns'] = Schema::getColumnListing('network_types');
        }

        return $out;
    }
}
