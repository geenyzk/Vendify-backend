<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'user_id' => $this->user_id,
            'amount' => $this->amount,
            'status' => $this->status,
            'transaction_type' => $this->transaction_type,
            'reference' => $this->transaction_reference,
            'promotion_id' => $this->promotion_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            "provider" => $this->provider,
            'recipient' => $this->receiver ?? $this->account_or_phone ?? "Anonymous"
        ];
    }
}
