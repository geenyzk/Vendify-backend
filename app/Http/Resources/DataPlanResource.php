<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $provider = $this->relationLoaded('providers')
            ? $this->providers->first()
            : $this->provider;

        $pivot = $provider?->pivot;

        return [
            'id' => $this->id,
            'plan_name' => $this->plan_name,
            'plan_size' => $this->plan_size,
            'plan_type' => $this->plan_type,
            'plan' => $this->plan,
            'network' => $this->network,
            'validity' => $this->validity,
            'active' => $this->active,
            'status' => $this->status,

            // Pricing
            'pricing' => $this->pricing,
            // Backwards-compatible individual fields (optional)

            'price' => $this->price,
            'price_ngn' => $this->price_ngn,

            // Servers and their configurations
            'cost_price' => $pivot?->cost_price ?? 0,
            'server_id' => $pivot?->server_id ?? 0,
            // 'server_id' =>
            'provider_id' => $pivot?->provider_id ?? $provider?->id,
            // 'pivot' => $this->provider->pivot,
            // Providers (vendors) offering this plan with pivot info
            'provider' => $provider ? [
                'id' => $provider->id,
                'name' => $provider->name,
                'code' => $provider->code,
                'sub_category' => $provider->sub_category,
                'category' => $provider->category,
                'pivot' => [
                    'provider_id' => $pivot?->provider_id ?? $provider->id,
                    'cost_price' => $pivot?->cost_price ?? 0,
                    'server_id' => $pivot?->server_id ?? 0,
                    'margin_value' => $pivot?->margin_value ?? null,
                    'margin_type' => $pivot?->margin_type ?? null,
                ],
            ] : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sort_order' => $this->sort_order
        ];
    }
}
