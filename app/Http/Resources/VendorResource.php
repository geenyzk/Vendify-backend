<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

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
            'base_url' => $this->base_url,
            'balance' => $this->balance,
            'connection' => $this->connection,
            'username' => $this->username,
            'password' => $this->password,
            'api_key' => $this->when(Auth::user()?->user_type === 'admin', $this->api_key),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sub_category' => $this->sub_category,
            'identifier' => $this->identifier,
            'webhook' => $this->webhook,
            'auto_fund_enabled' => $this->auto_fund_enabled,
            'auto_fund_threshold' => $this->auto_fund_threshold,
            'auto_fund_amount' => $this->auto_fund_amount,
            'account_number' => $this->account_number,
            'account_name' => $this->account_name,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'funding_provider_id' => $this->funding_provider_id,
        ];
    }
}
