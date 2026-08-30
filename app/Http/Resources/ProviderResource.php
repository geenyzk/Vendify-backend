<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Connection here means the admin's enabled/disabled gateway state.
        // Do not call a live provider API while rendering a list of gateways;
        // that makes the screen slow and can make a healthy saved connection
        // look disconnected when a provider is temporarily unavailable.
        // Each gateway integration reads a different combination of these
        // (Flutterwave: api_key only. Monnify: api_key + secret_key +
        // username. PaymentPoint: password + api_key) — expose them
        // distinctly instead of merging, so editing one doesn't silently
        // no-op for a gateway that actually reads a different column.
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'balance' => $this->balance,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'username' => $this->username,
            'credentials_configured' => (bool) ($this->password || $this->api_key || $this->secret_key || $this->encryption_key),
            'connection' => (bool) $this->active,
            'active' => (bool) $this->active,
            'plans_count' => $this->plans_count,
            'active_plans_count' => $this->active_plans_count,
            'identifier' => $this->identifier,
            'webhook' => $this->webhook,
            'charge_fee' => $this->charge_fee,
            'charge_fee_cap' => $this->charge_fee_cap,
            'charge_type' => $this->charge_type,
            'withdrawal_fee' => $this->withdrawal_fee,
            'withdrawal_fee_type' => $this->withdrawal_fee_type,
            'manual_balance' => $this->manual_balance,
        ];
    }
}
