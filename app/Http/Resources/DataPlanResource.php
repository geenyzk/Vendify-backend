<?php

namespace App\Http\Resources;

use App\Support\ProviderPlanPresentation;
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
        $presentation = ProviderPlanPresentation::from($pivot?->provider_plan_name, $this->validity);
        $fallbackProvider = $this->fallback_provider;

        return [
            'id' => $this->id,
            'plan_name' => $this->plan_name,
            'plan_size' => $this->plan_size,
            'plan_type' => $this->networkType?->name ?? $this->plan_type,
            'network_type_id' => $this->network_type_id,
            'plan' => $this->plan,
            'network' => $this->network,
            'validity' => $this->validity,
            'provider_plan_name' => $presentation['original'],
            'provider_plan_description' => $presentation['description'],
            'provider_plan_parse_confident' => $presentation['confident'],
            'active' => $this->active,
            'is_featured' => (bool) $this->is_featured,
            'auto_category' => $this->autoCategory ? ['id' => $this->autoCategory->id, 'name' => $this->autoCategory->name, 'slug' => $this->autoCategory->slug] : null,
            'manual_category' => $this->manualCategory ? ['id' => $this->manualCategory->id, 'name' => $this->manualCategory->name, 'slug' => $this->manualCategory->slug] : null,
            'category' => $this->effective_category?->name,
            'category_slug' => $this->effective_category?->slug,
            'status' => $this->status,

            // Pricing
            'pricing' => $this->pricing,
            // Backwards-compatible individual fields (optional)

            'price' => $this->price,
            'price_ngn' => $this->price_ngn,

            // Servers and their configurations
            'cost_price' => $pivot?->cost_price ?? 0,
            'provider_price' => $pivot?->provider_price,
            'server_id' => $pivot?->server_id ?? 0,
            'external_plan_id' => $pivot?->external_plan_id,
            // 'server_id' =>
            'provider_id' => $pivot?->provider_id ?? $provider?->id,
            'fallback_provider_id' => $this->fallback_provider_id,
            'fallback_server_id' => $this->fallback_server_id,
            'fallbacks' => $this->fallbacks,
            // Null = no distinct fallback price; sales served by the fallback
            // are then costed at the primary's cost_price above.
            'fallback_cost_price' => $this->fallback_cost_price,
            'fallback_provider' => $fallbackProvider ? [
                'id' => $fallbackProvider->id,
                'name' => $fallbackProvider->name,
            ] : null,
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
                    'provider_price' => $pivot?->provider_price,
                    'server_id' => $pivot?->server_id ?? 0,
                    'external_plan_id' => $pivot?->external_plan_id,
                    'margin_value' => $pivot?->margin_value ?? null,
                    'margin_type' => $pivot?->margin_type ?? null,
                ],
            ] : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sort_order' => $this->sort_order,
        ];
    }
}
