<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'airtime_api_id' => $this->airtime_api_id,
            'data_api_id' => $this->data_api_id,
            'airtime_recharge_api_id' => $this->airtime_recharge_api_id,
            'data_recharge_api_id' => $this->data_recharge_api_id,
            'airtime_to_cash_destination_number' => $this->airtime_to_cash_destination_number,
            'airtime_to_cash_min' => $this->airtime_to_cash_min,
            'airtime_to_cash_max' => $this->airtime_to_cash_max,
            'airtime_to_cash_active' => $this->airtime_to_cash_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Include the eager-loaded relationship with pivot data
            'network_types' => NetworkTypeResource::collection($this->whenLoaded('networkTypes')),
        ];
    }
}
