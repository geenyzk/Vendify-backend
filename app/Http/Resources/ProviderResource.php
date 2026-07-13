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
        // Note: every PaymentFactory provider's connect() is currently an
        // unimplemented stub that returns "" — calling it here would turn a
        // real `connection` boolean into a falsy empty string, making every
        // connected payment gateway look disconnected. Report the raw DB
        // flag until connect() actually does a live check.
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
            'password' => $this->password,
            'api_key' => $this->api_key,
            'secret_key' => $this->secret_key,
            "connection" => $this->connection,
            'identifier' => $this->identifier,
            'webhook' => $this->webhook,
            'manual_balance' => $this->manual_balance,
        ];
    }
}
