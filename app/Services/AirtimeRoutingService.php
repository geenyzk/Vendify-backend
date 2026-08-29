<?php

namespace App\Services;

use App\Classes\Vendor\VendorFactory;
use App\Models\AirtimePlan;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

final class AirtimeRoutingService
{
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
