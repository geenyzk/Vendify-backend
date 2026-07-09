<?php

namespace App\Http\Controllers;

use App\Models\AirtimePlan;
use App\Models\BillPlan;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\DiscoProviderId;
use App\Models\NetworkType;
use App\Models\ServiceRoute;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Backs the admin "Service Routing" screen. The set of routable rows is derived
 * live from the catalog (network types, data plan types, cable networks, discos)
 * rather than a fixed schema, so a brand-new category/network/disco shows up for
 * routing with no migration. Assignments persist to `service_routes`, which
 * VTUServiceFactory consults as an override above the legacy stock_vendings
 * fallback.
 */
class ServiceRoutingController extends Controller
{
    // Pretty labels for known machine keys; everything else is Title-Cased.
    private const LABELS = [
        'sme' => 'SME',
        'vtu' => 'VTU',
        'sns' => 'SNS',
        'gifting' => 'Gifting',
        'cooperate gifting' => 'Corporate Gifting',
        'cg' => 'Corporate Gifting',
        'dstv' => 'DStv',
        'gotv' => 'GOtv',
        'startimes' => 'StarTimes',
        'exam' => 'Exam / Result Checker',
    ];

    public function index(): JsonResponse
    {
        $vendors = Vendor::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])
            ->values();

        $assignments = $this->currentAssignments();

        $groups = [
            $this->buildGroup('data', 'Data', $this->dataKeys(), $assignments),
            $this->buildGroup('airtime', 'Airtime', $this->airtimeKeys(), $assignments),
            $this->buildGroup('cable', 'Cable TV', $this->cableKeys(), $assignments),
            $this->buildGroup('electricity', 'Electricity', $this->electricityKeys(), $assignments),
            $this->buildGroup('exam', 'Exam', [['key' => 'exam', 'label' => self::LABELS['exam']]], $assignments),
        ];

        return $this->success([
            'vendors' => $vendors,
            'groups' => $groups,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'routes' => 'required|array',
            'routes.*.service_type' => 'required|string',
            'routes.*.route_key' => 'nullable|string',
            'routes.*.provider_id' => 'nullable|integer|exists:providers,id',
        ]);

        foreach ($data['routes'] as $route) {
            ServiceRoute::updateOrCreate(
                [
                    'service_type' => $route['service_type'],
                    'route_key' => $route['route_key'] ?? '',
                ],
                ['provider_id' => $route['provider_id'] ?? null],
            );
        }

        return $this->success(null, 'Service routing updated');
    }

    // ── Assignment lookup ───────────────────────────────────────────────────

    /** Normalized "service|key" => provider_id for every saved route. */
    private function currentAssignments(): array
    {
        $map = [];
        foreach (ServiceRoute::all() as $route) {
            $map[$this->norm($route->service_type) . '|' . $this->norm($route->route_key)] = $route->provider_id;
        }
        return $map;
    }

    private function buildGroup(string $serviceType, string $label, array $keys, array $assignments): array
    {
        $routes = [];
        foreach ($keys as $k) {
            $mapKey = $this->norm($serviceType) . '|' . $this->norm($k['key']);
            $routes[] = [
                'service_type' => $serviceType,
                'route_key' => $k['key'],
                'label' => $k['label'],
                'provider_id' => $assignments[$mapKey] ?? null,
            ];
        }

        return [
            'service_type' => $serviceType,
            'label' => $label,
            'routes' => $routes,
        ];
    }

    // ── Dynamic key enumeration (per service) ───────────────────────────────

    private function dataKeys(): array
    {
        $raw = collect()
            ->merge(NetworkType::where('service_type', 'data')->pluck('name'))
            ->merge(DataPlan::query()->whereNotNull('plan_type')->distinct()->pluck('plan_type'));

        return $this->dedupe($raw);
    }

    private function airtimeKeys(): array
    {
        $raw = collect()
            ->merge(NetworkType::where('service_type', 'airtime')->pluck('name'))
            ->merge(AirtimePlan::query()->pluck('category')->map(fn ($c) => $c ?: 'vtu'));

        return $this->dedupe($raw);
    }

    private function cableKeys(): array
    {
        // Cable networks (DStv/GOtv/StarTimes) are NetworkType rows with
        // service_type = "cable" — the same catalog data/airtime categories
        // live in — so read from there, plus any cable_network actually used on
        // a plan for safety.
        $raw = collect()
            ->merge(NetworkType::where('service_type', 'cable')->pluck('name'))
            ->merge(CablePlan::query()->whereNotNull('cable_network')->distinct()->pluck('cable_network'));

        return $this->dedupe($raw);
    }

    private function electricityKeys(): array
    {
        $raw = collect()
            ->merge(DiscoProviderId::query()->pluck('name'))
            ->merge(BillPlan::query()->whereNotNull('disco')->distinct()->pluck('disco'));

        return $this->dedupe($raw);
    }

    /**
     * Collapse a raw list of keys to unique, non-empty entries (case/separator
     * insensitive), each with a display label.
     */
    private function dedupe(Collection $raw): array
    {
        $seen = [];
        $out = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $n = $this->norm($value);
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $out[] = ['key' => $value, 'label' => $this->humanize($value)];
        }

        return $out;
    }

    private function humanize(string $value): string
    {
        $n = $this->norm($value);
        return self::LABELS[$n] ?? ucwords($n);
    }

    private function norm(string $value): string
    {
        return strtolower(str_replace(['_', '-'], ' ', trim($value)));
    }
}
