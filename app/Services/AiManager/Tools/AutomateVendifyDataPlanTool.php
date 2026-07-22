<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\User;
use App\Services\AiManager\VendifyDataPlanBrowser;
use App\Support\AuditLogger;

class AutomateVendifyDataPlanTool extends AiTool
{
    public function name(): string { return 'automate_vendify_data_plan'; }
    public function description(): string
    {
        return 'Propose a restricted browser run that creates or edits exactly one Vendify data plan. The approval card is the preview. It preserves omitted role prices, captures before/after screenshots, submits once, reopens the plan, and verifies saved values. It cannot delete, purchase, access wallets, run scripts, download files, or leave Data Plans.';
    }
    public function isMutating(): bool { return true; }
    public function permission(): ?string { return 'settings'; }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'enum' => ['create', 'update']],
            'plan_id' => ['type' => 'integer', 'description' => 'Required for update.'],
            'network' => ['type' => 'string'], 'type' => ['type' => 'string'],
            'amount' => ['type' => 'number'], 'unit' => ['type' => 'string', 'enum' => ['MB', 'GB']],
            'validity' => ['type' => 'string'], 'active' => ['type' => 'boolean'],
            'route_to_provider' => ['type' => 'boolean'],
            'provider' => ['type' => 'string', 'description' => 'Approved primary provider name, e.g. Quickly Sim.'],
            'primary_plan_id' => ['type' => 'integer'], 'cost_price' => ['type' => 'number'],
            'role_pricing' => ['type' => 'array', 'description' => 'Only supplied role prices are changed; omitted roles are preserved.', 'items' => [
                'type' => 'object', 'properties' => [
                    'role' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['fiat', 'percentage']], 'value' => ['type' => 'number'],
                ], 'required' => ['role', 'type', 'value'],
            ]],
            'reason' => ['type' => 'string'],
        ], 'required' => ['action']];
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:create,update', 'plan_id' => 'nullable|integer|min:1|required_if:action,update',
            'network' => 'nullable|string|max:80|required_if:action,create', 'type' => 'nullable|string|max:80|required_if:action,create',
            'amount' => 'nullable|numeric|min:0.01|required_if:action,create', 'unit' => 'nullable|in:MB,GB|required_if:action,create',
            'validity' => 'nullable|string|max:80|required_if:action,create', 'active' => 'nullable|boolean',
            'route_to_provider' => 'nullable|boolean', 'provider' => 'nullable|string|max:100|required_if:route_to_provider,true',
            'primary_plan_id' => 'nullable|integer|min:0', 'cost_price' => 'nullable|numeric|min:0',
            'role_pricing' => 'nullable|array|max:100', 'role_pricing.*.role' => 'required|string|max:80', 'role_pricing.*.type' => 'required|in:fiat,percentage',
            'role_pricing.*.value' => 'required|numeric|min:0', 'reason' => 'nullable|string|max:1000',
        ];
    }

    public function summarize(array $arguments): string
    {
        $target = $arguments['action'] === 'create' ? 'a new data plan' : 'data plan #'.$arguments['plan_id'];
        $changes = collect($arguments)->except(['action', 'plan_id', 'reason'])->filter(fn ($v) => $v !== null)->keys()->implode(', ');
        return ucfirst($arguments['action'])." {$target} in Vendify via browser; previewed fields: {$changes}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $result = app(VendifyDataPlanBrowser::class)->run($arguments);
        $plan = isset($result['plan_id']) ? DataPlan::find($result['plan_id']) : null;
        AuditLogger::record('ai.browser.data_plan_saved', $plan, description: 'Approved AI Manager browser automation saved and verified a Vendify data plan.', context: ['run_id' => $result['run_id'], 'request' => $arguments, 'verification' => $result['verification'] ?? null, 'artifacts' => $result['artifacts'] ?? []], actor: $actor);
        return $result;
    }
}
