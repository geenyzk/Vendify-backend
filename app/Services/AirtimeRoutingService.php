<?php

namespace App\Services;

use App\Classes\Vendor\VendorFactory;
use App\Models\AirtimePlan;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

final class AirtimeRoutingService
{
    public function providers(): Collection
    {
        return Vendor::query()->where('active', true)->orderBy('name')->get()
            ->filter(function (Vendor $vendor) {
                try {
                    return VendorFactory::make($vendor)->supportsService('airtime');
                } catch (\Throwable) {
                    return false;
                }
            })
            ->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'name' => match ($vendor->sub_category) {
                    'cheapdatahub' => 'CheapDataHub',
                    'vtu_ng' => 'VTU.ng',
                    default => $vendor->name,
                },
            ])->values();
    }

    public function plan(string $network, ?string $category): ?AirtimePlan
    {
        $plans = AirtimePlan::query()->where('name', $network)->where('active', true)->get();

        return $plans->first(fn (AirtimePlan $plan) => ($plan->category ?: 'vtu') === ($category ?: 'vtu'));
    }

    public function primary(AirtimePlan $plan): ?Vendor
    {
        return $plan->resolveVendor();
    }

    public function assertProvider($providerId, string $field = 'providerable.provider_id'): Vendor
    {
        $provider = Vendor::find($providerId);
        if (! $provider || ! $provider->active) {
            throw ValidationException::withMessages([$field => 'Select an active airtime provider.']);
        }

        try {
            if (! VendorFactory::make($provider)->supportsService('airtime')) {
                throw ValidationException::withMessages([$field => 'The selected provider does not support airtime.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([$field => 'The selected provider cannot be used for airtime.']);
        }

        return $provider;
    }
}
