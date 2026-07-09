<?php

namespace App\Services\AiManager\Tools;

use App\Models\Promotion;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

/**
 * Create a promo code / auto promotion. Mirrors the core of
 * PromotionController::store (the optional per-condition rules are out of scope
 * for the assistant). Mutating: proposal-only, gated by `settings`.
 */
class CreatePromotionTool extends AiTool
{
    public function name(): string
    {
        return 'create_promotion';
    }

    public function description(): string
    {
        return 'Propose creating a promotion — either an auto-applied promo or one that customers redeem with a code — for airtime, data, or bundles. Supports percentage, fixed, bonus_data, or cashback rewards, an optional window, and usage limits. Creates a pending action an admin must approve.';
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
                'name' => ['type' => 'string'],
                'apply' => ['type' => 'string', 'enum' => ['auto', 'code'], 'description' => 'auto = applied automatically; code = customer enters a code.'],
                'code' => ['type' => 'string', 'description' => 'Required when apply is "code". Redemption code.'],
                'target' => ['type' => 'string', 'enum' => ['customer', 'reseller', 'both']],
                'product' => ['type' => 'string', 'enum' => ['airtime', 'data', 'bundle']],
                'type' => ['type' => 'string', 'enum' => ['percentage', 'fixed', 'bonus_data', 'cashback']],
                'value' => ['type' => 'number'],
                'active' => ['type' => 'boolean', 'description' => 'Default true.'],
                'starts_at' => ['type' => 'string', 'description' => 'Optional ISO start date.'],
                'ends_at' => ['type' => 'string', 'description' => 'Optional ISO end date (>= starts_at).'],
                'usage_limit_total' => ['type' => 'integer', 'description' => 'Optional total redemption cap.'],
                'usage_limit_per_customer' => ['type' => 'integer', 'description' => 'Optional per-customer redemption cap.'],
            ],
            'required' => ['name', 'apply', 'target', 'product', 'type', 'value'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'apply' => 'required|in:auto,code',
            'code' => 'nullable|string|max:50|required_if:apply,code|unique:promotions,code',
            'target' => 'required|in:customer,reseller,both',
            'product' => 'required|in:airtime,data,bundle',
            'type' => 'required|in:percentage,fixed,bonus_data,cashback',
            'value' => 'required|numeric|min:0',
            'active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_customer' => 'nullable|integer|min:1',
        ];
    }

    public function summarize(array $arguments): string
    {
        $how = $arguments['apply'] === 'code'
            ? "code " . strtoupper($arguments['code'] ?? '')
            : 'auto';

        return "Create promotion '{$arguments['name']}' ({$how}) — {$arguments['type']} {$arguments['value']} on {$arguments['product']} for {$arguments['target']}";
    }

    public function handle(array $arguments, User $actor): array
    {
        if (($arguments['apply'] ?? null) === 'code' && empty($arguments['code'])) {
            throw new AiManagerException('A code is required for a code-based promotion.');
        }

        $data = [
            'name' => $arguments['name'],
            'apply' => $arguments['apply'],
            'target' => $arguments['target'],
            'product' => $arguments['product'],
            'type' => $arguments['type'],
            'value' => $arguments['value'],
            'active' => $arguments['active'] ?? true,
            'starts_at' => $arguments['starts_at'] ?? null,
            'ends_at' => $arguments['ends_at'] ?? null,
            'usage_limit_total' => $arguments['usage_limit_total'] ?? null,
            'usage_limit_per_customer' => $arguments['usage_limit_per_customer'] ?? null,
        ];

        if (!empty($arguments['code'])) {
            $data['code'] = strtoupper($arguments['code']);
        }

        $promotion = Promotion::create($data);

        return [
            'created' => true,
            'promotion_id' => $promotion->id,
            'name' => $promotion->name,
            'code' => $promotion->code,
        ];
    }
}
