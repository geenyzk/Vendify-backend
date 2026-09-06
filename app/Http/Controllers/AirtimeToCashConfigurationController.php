<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Network;
use App\Services\AirtimeToCashAvailabilityService;
use App\Support\PerformanceCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AirtimeToCashConfigurationController extends Controller
{
    public function index()
    {
        return $this->success(Network::orderBy('name')->get()->map(fn ($network) => $this->present($network)))
            ->header('Cache-Control', 'private, no-store');
    }

    private function present(Network $network): array
    {
        $policy = app(AirtimeToCashAvailabilityService::class);
        $rates = $policy->rates($network);
        $rate = $policy->rate($network) ?? $rates->first(fn ($rate) => $rate->network !== null) ?? $rates->first();

        return [
            'id' => $network->id, 'name' => $network->name,
            'enabled' => $network->airtime_to_cash_active,
            'destination_number' => $network->airtime_to_cash_destination_number,
            'min_amount' => (float) $network->airtime_to_cash_min,
            'max_amount' => (float) $network->airtime_to_cash_max,
            'rate' => $rate ? $rate->only(['id', 'network', 'discount_type', 'value', 'active', 'starts_at', 'ends_at']) : null,
            'availability' => $policy->inspect($network),
        ];
    }

    public function update(Request $request, Network $network)
    {
        $input = $request->validate([
            'enabled' => 'required|boolean',
            'destination_number' => ['nullable', 'required_if:enabled,true', 'regex:/^0[789][0-9]{9}$/'],
            'min_amount' => 'required|numeric|gt:0',
            'max_amount' => 'required|numeric|gte:min_amount|max:99999999.99',
            'rate_type' => 'nullable|required_if:enabled,true|in:percentage,fixed',
            'rate_value' => 'nullable|required_with:rate_type|numeric|min:0',
            'rate_active' => 'required|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);
        if ($input['enabled'] && ! $input['rate_active']) {
            throw ValidationException::withMessages(['rate_active' => 'Cannot enable conversion without an active rate.']);
        }
        $network = DB::transaction(function () use ($network, $input) {
            $network = Network::lockForUpdate()->findOrFail($network->id);
            $policy = app(AirtimeToCashAvailabilityService::class);
            if ($input['enabled'] && Network::all()->filter(fn ($other) => $other->id !== $network->id && $policy::canonicalName($other->name) === $policy::canonicalName($network->name))->isNotEmpty()) {
                throw ValidationException::withMessages(['enabled' => 'Duplicate network names must be resolved before enabling conversion.']);
            }
            if (! empty($input['rate_type'])) {
                // Preserve generic defaults and historical rows. Consolidate only this
                // network's existing rules into the explicitly saved configuration.
                $rates = $policy->rates($network)->filter(fn ($rate) => $rate->network !== null);
                $rate = $policy->rate($network);
                if (! $rate || $rate->network === null) {
                    $rate = $rates->first() ?? new Discount;
                }
                foreach ($rates as $other) {
                    if ($other->id !== $rate->id) {
                        $other->update(['active' => false]);
                    }
                }
                $rate->fill([
                    'name' => $network->name.' Airtime to Cash', 'service_type' => 'airtimeToCash',
                    'network' => $policy::canonicalName($network->name),
                    'discount_type' => $input['rate_type'], 'value' => $input['rate_value'],
                    'active' => $input['rate_active'], 'starts_at' => $input['starts_at'] ?? null,
                    'ends_at' => $input['ends_at'] ?? null,
                ])->save();
            }
            $network->fill([
                'airtime_to_cash_active' => $input['enabled'],
                'airtime_to_cash_destination_number' => $input['destination_number'] ?? null,
                'airtime_to_cash_min' => $input['min_amount'], 'airtime_to_cash_max' => $input['max_amount'],
            ]);
            $availability = $policy->inspect($network);
            if ($input['enabled'] && ! $availability['available']) {
                throw ValidationException::withMessages(['enabled' => "Cannot enable {$network->name}: {$availability['reason']}"]);
            }
            $network->save();

            return $network;
        });
        PerformanceCache::clearCatalog();

        return $this->success($this->present($network), 'Airtime-to-Cash configuration saved');
    }
}
