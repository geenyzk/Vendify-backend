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
        // This resource serves both the customer's own transaction lookup
        // (TransactionController::showOwn) and the admin status/refund/requery
        // screens. Routing/vendor identity is operational data: staff keep it
        // for reconciliation, customers never see it.
        $isStaff = (bool) ($request->user()?->role?->is_staff);

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
            // Provider adapter keys are operational details. Electricity
            // receipts should name the disco that supplied the meter instead;
            // every other service tells a customer nothing about the vendor.
            "provider" => $isStaff
                ? ($this->transaction_type === 'electric_bill'
                    ? ($this->distribution_company ?? $this->electricityProviderLabel())
                    : $this->provider)
                : $this->customerFacingProvider(),
            'network' => $this->network,
            'airtime_plan_id' => $this->airtime_plan_id,
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
            // Reconciliation fields — which vendor was tried, which one
            // served it. Admin-only, and omitted entirely for customers
            // rather than nulled, so nothing hints at the routing.
            $this->mergeWhen($isStaff, fn () => [
                'provider_key' => $this->provider,
                'primary_provider_id' => $this->primary_provider_id,
                'final_provider_id' => $this->final_provider_id,
            ]),
            'plan_type' => $this->plan_type,
            'token' => $this->token,
            'service' => $this->service,
            'meter_type' => $this->meter_type,
            'meter_number' => $this->meter_number,
            'customer_name' => $this->customer_name,
            'distribution_company' => $this->distribution_company,
            'electricity_token' => $this->electricity_token,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'fullname' => $this->user->fullname,
                'email' => $this->user->email,
            ]),
        ];
    }

    private function electricityProviderLabel(): ?string
    {
        return $this->provider === 'electricity_sandbox' ? 'Ikeja Electric' : $this->provider;
    }
}
