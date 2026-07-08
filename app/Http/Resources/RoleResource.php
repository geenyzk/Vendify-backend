<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
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
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'upgradable' => $this->upgradable,
            'upgrade_cost' => $this->upgrade_cost,
            'service_cost_margins' => ServiceCostMarginResource::collection($this->whenLoaded('serviceCostMargins')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
