<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use App\Models\Discount;

class NetworkTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        // Determine pivot data: prefer $this->pivot (when loaded through a relationship)
        $pivot = $this->pivot ?? null;

        // If pivot is not available, try to derive it from the loaded networks relationship
        if (!$pivot) {
            if ($this->relationLoaded('networks') && $this->networks->isNotEmpty()) {
                $firstNetwork = $this->networks->first();
                $pivot = $firstNetwork->pivot ?? null;
            }
        }

        // As a last resort, query the pivot table for the base entry (network_id IS NULL)
        if (!$pivot) {
            try {
                $base = \Illuminate\Support\Facades\DB::table('network_network_type')
                    ->where('network_type_id', $this->id)
                    ->whereNull('network_id')
                    ->first();

                if ($base) {
                    $pivot = $base;
                } else {
                    $any = \Illuminate\Support\Facades\DB::table('network_network_type')
                        ->where('network_type_id', $this->id)
                        ->first();
                    if ($any) {
                        $pivot = $any;
                    }
                }
            } catch (\Exception $e) {
                // swallow; resource should not break on DB lookup failure
            }
        }

        $serviceType = $this->service_type ?? ($pivot->service_type ?? 'unknown');

        $pivotData = null;
        if ($pivot) {
            $pivotData = [
                'network_id' => $pivot->network_id ?? null,
                'network_type_id' => $pivot->network_type_id ?? $this->id,
                'service_type' => $pivot->service_type ?? '--',
                'active' => isset($pivot->active) ? (bool)$pivot->active : null,
                'created_at' => $pivot->created_at ?? null,
                'updated_at' => $pivot->updated_at ?? null,
            ];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_type' => $serviceType,
            'active' => (bool)$this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'pivot' => $pivotData,
            'provider_id' => $this->provider_id,
            "provider" => $this->whenLoaded('provider', function() {
                return new VendorResource($this->provider);
            }),
            'discount' => $this->getAirtimeDiscount($serviceType),

        ];
    }

    /**
     * Get discount for airtime service type
     * Fetches discount where network is network name and category is network type name
     */
    private function getAirtimeDiscount(?string $serviceType = null):?array
    {

        if ($serviceType !== 'airtime') {
            return null;
        }

        try {
            $discount = Discount::where('service_type', 'airtime')->where('network', $this->name)->first();
            if ($discount) {
                return [
                    'id' => $discount->id,
                    'network' => $discount->network,
                    'discount_type' => $discount->discount_type,
                    'value' => $discount->value,
                    'min' => $discount->min,
                    'max' => $discount->max,
                    'active' => $discount->active,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch discount for network type", ['network_type' => $this->name, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
