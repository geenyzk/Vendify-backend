<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
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
            'code' => $this->code,
            'balance' => $this->balance,
            'connection' => $this->connection,
            'username' => $this->username,
            'password' => $this->password,
            'api_key' => $this->when($request->user()?->user_type === 'admin', $this->api_key),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sub_category' => $this->sub_category
        ];
    }
}
