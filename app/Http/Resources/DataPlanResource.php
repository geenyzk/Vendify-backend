<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class DataPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $p = DB::table('providerables')
            ->where('providerable_id', $this->id)
            ->where('providerable_type', 'App\Models\DataPlan')
            ->first();
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
            'cost_price' => $this->provider->pivot->cost_price ?? $p->cost_price ?? 0,
            'server_id' => $this->provider->pivot->server_id ?? $p->server_id ?? 0,
            // 'server_id' =>
            'provider_id' => $this->provider->pivot->provider_id ?? null,
            // 'pivot' => $this->provider->pivot,
            // Providers (vendors) offering this plan with pivot info
            'provider' =>  $this->provider,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sort_order' => $this->sort_order
        ];
    }
}
