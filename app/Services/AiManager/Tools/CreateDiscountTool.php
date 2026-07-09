<?php

namespace App\Services\AiManager\Tools;

use App\Models\Discount;
use App\Models\User;

/**
 * Create a Discount — the automatic, no-code price cut the platform applies to
 * a service type (optionally one network) for a window. Mirrors
 * DiscountController::store validation. Mutating: proposal-only, gated by
 * `settings`.
 */
class CreateDiscountTool extends AiTool
{
    public function name(): string
    {
        return 'create_discount';
    }

    public function description(): string
    {
        return 'Propose creating an automatic discount (a flash-sale-style price cut applied without a code) on a service type, optionally limited to one network and/or a date window. Creates a pending action an admin must approve before it goes live.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'settings';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Label for the discount.'],
                'service_type' => ['type' => 'string', 'enum' => ['airtime', 'data', 'cable', 'electricity', 'exam', 'airtimeToCash']],
                'network' => ['type' => 'string', 'description' => 'Optional single network to limit to (e.g. mtn).'],
                'discount_type' => ['type' => 'string', 'enum' => ['percentage', 'fixed']],
                'value' => ['type' => 'number', 'description' => 'Percent (for percentage) or naira amount (for fixed).'],
                'active' => ['type' => 'boolean', 'description' => 'Whether it is live immediately. Default true.'],
                'starts_at' => ['type' => 'string', 'description' => 'Optional ISO start date.'],
                'ends_at' => ['type' => 'string', 'description' => 'Optional ISO end date (>= starts_at).'],
            ],
            'required' => ['name', 'service_type', 'discount_type', 'value'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'service_type' => 'required|in:airtime,data,cable,electricity,exam,airtimeToCash',
            'network' => 'nullable|string|max:255',
            'discount_type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ];
    }

    public function summarize(array $arguments): string
    {
        $amount = $arguments['discount_type'] === 'percentage'
            ? "{$arguments['value']}%"
            : 'NGN ' . number_format((float) $arguments['value'], 2);
        $scope = $arguments['service_type'] . (!empty($arguments['network']) ? " ({$arguments['network']})" : '');

        return "Create discount '{$arguments['name']}': {$amount} off {$scope}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $discount = Discount::create([
            'name' => $arguments['name'],
            'service_type' => $arguments['service_type'],
            'network' => $arguments['network'] ?? null,
            'discount_type' => $arguments['discount_type'],
            'value' => $arguments['value'],
            'active' => $arguments['active'] ?? true,
            'starts_at' => $arguments['starts_at'] ?? null,
            'ends_at' => $arguments['ends_at'] ?? null,
        ]);

        return [
            'created' => true,
            'discount_id' => $discount->id,
            'name' => $discount->name,
        ];
    }
}
