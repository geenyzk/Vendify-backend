<?php

namespace App\Http\Resources;

use App\Support\VendorErrorMessage;
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
            'payment_reference' => $this->payment_reference,
            'promotion_id' => $this->promotion_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            "provider" => $this->provider,
            'network' => $this->network,
            'airtime_plan_id' => $this->airtime_plan_id,
            'primary_provider_id' => $this->primary_provider_id,
            'final_provider_id' => $this->final_provider_id,
            'fallback_used' => (bool) $this->fallback_used,
            'is_sandbox' => (bool) $this->is_sandbox,
            'recipient' => $this->receiver ?? $this->account_or_phone ?? "Anonymous",
            'account_or_phone' => $this->account_or_phone,
            'quantity' => $this->quantity,
            'discount_amount' => $this->discount_amount,
            'service_fee' => $this->service_fee,
            'funding_method' => $this->funding_method,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'completed_at' => $this->completed_at,
            'refunded_at' => $this->refunded_at,
            'refund_reason' => $this->refund_reason,
            'response_message' => $this->provider && in_array($this->status, ['fail', 'pending'], true)
                ? VendorErrorMessage::forCurrentUser($this->response_message, $this->status)
                : $this->response_message,
            'platform' => $this->platform,
            'plan_type' => $this->plan_type,
            'token' => $this->token,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'fullname' => $this->user->fullname,
                'email' => $this->user->email,
            ]),
        ];
    }
}
