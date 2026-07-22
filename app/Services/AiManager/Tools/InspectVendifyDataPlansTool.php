<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use App\Services\AiManager\VendifyDataPlanBrowser;
use App\Support\AuditLogger;

class InspectVendifyDataPlansTool extends AiTool
{
    public function name(): string { return 'inspect_vendify_data_plans'; }

    public function description(): string
    {
        return 'Open the first-party Vendify Data Plans admin screen, optionally open one plan, read the visible values, and capture a screenshot. This cannot submit changes or leave the approved Data Plans routes.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'plan_id' => ['type' => 'integer', 'description' => 'Optional plan id to open in read-only inspection mode.'],
        ]];
    }

    public function rules(): array { return ['plan_id' => 'nullable|integer|min:1']; }

    public function handle(array $arguments, User $actor): array
    {
        $result = app(VendifyDataPlanBrowser::class)->run([
            'action' => 'inspect',
            'planId' => $arguments['plan_id'] ?? null,
        ]);
        AuditLogger::record('ai.browser.inspect', description: 'AI Manager inspected Vendify Data Plans.', context: ['run_id' => $result['run_id'], 'plan_id' => $arguments['plan_id'] ?? null], actor: $actor);
        return $result;
    }
}
