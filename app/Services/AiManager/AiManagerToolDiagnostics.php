<?php

namespace App\Services\AiManager;

use App\Models\User;
use App\Services\AiManager\Tools\ToolRegistry;
use Illuminate\Support\Facades\Process;

class AiManagerToolDiagnostics
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly VendifyDataPlanBrowser $browser,
    ) {
    }

    public function report(?User $actor = null, bool $probeBrowser = true): array
    {
        $browser = $this->browserStatus($probeBrowser);
        $tools = collect($this->registry->all())->map(function ($tool) use ($actor) {
            $enabled = $this->registry->enabled($tool);
            $permission = $tool->permission();
            $permitted = $actor === null
                ? null
                : ($permission === null || (bool) ($actor->role?->hasPermission($permission) ?? false));

            $reason = $this->registry->disabledReason($tool);
            if ($enabled && $permitted === false) {
                $reason = "Actor lacks required permission '{$permission}'.";
            }

            return [
                'name' => $tool->name(),
                'registered' => true,
                'enabled' => $enabled,
                'reason' => $reason,
                'permission' => $permission,
                'permitted' => $permitted,
                'exposed_to_model' => $actor ? $this->registry->userMayUse($actor, $tool) : null,
                'mutating' => $tool->isMutating(),
            ];
        })->values()->all();

        return [
            'environment' => app()->environment(),
            'configuration_cached' => app()->configurationIsCached(),
            'feature_flag' => (bool) config('services.vendify_browser.enabled'),
            'registry_bound' => app()->bound(ToolRegistry::class),
            'browser_service_bound' => app()->bound(VendifyDataPlanBrowser::class),
            'manifest_loading' => 'rebuilt_for_each_model_turn',
            'manifest_cached_on_conversation' => false,
            'actor' => $actor ? ['id' => $actor->id, 'email' => $actor->email, 'role' => $actor->role?->slug] : null,
            'browser_health' => $browser,
            'tools' => $tools,
        ];
    }

    private function browserStatus(bool $probe): array
    {
        $state = (string) config('services.vendify_browser.storage_state');
        $resolvedState = $state !== '' && ! $this->absolutePath($state) ? base_path($state) : $state;
        $node = (string) config('services.vendify_browser.node_binary', 'node');
        try {
            $nodeResult = Process::timeout(5)->run([$node, '--version']);
            $nodeAvailable = $nodeResult->successful();
            $nodeVersion = $nodeAvailable ? trim($nodeResult->output()) : null;
            $nodeError = $nodeAvailable ? null : trim($nodeResult->errorOutput() ?: $nodeResult->output());
        } catch (\Throwable $exception) {
            $nodeAvailable = false;
            $nodeVersion = null;
            $nodeError = $exception->getMessage();
        }

        $status = [
            'authenticated' => false,
            'probe_ran' => false,
            'base_url' => config('services.vendify_browser.base_url'),
            'allowed_origins' => config('services.vendify_browser.allowed_origins', []),
            'state_path' => $resolvedState,
            'state_exists' => $resolvedState !== '' && is_file($resolvedState),
            'state_readable' => $resolvedState !== '' && is_readable($resolvedState),
            'node_binary' => $node,
            'node_available' => $nodeAvailable,
            'node_version' => $nodeVersion,
            'node_error' => $nodeError,
            'playwright_installed' => is_file(base_path('browser/node_modules/playwright/package.json')),
            'runner_exists' => is_file(base_path('browser/vendify-data-plans.mjs')),
            'queue_worker_required' => false,
        ];

        if (! $probe || ! config('services.vendify_browser.enabled') || ! $status['state_readable'] || ! $status['node_available'] || ! $status['playwright_installed'] || ! $status['runner_exists']) {
            return $status;
        }

        $status['probe_ran'] = true;
        try {
            $result = $this->browser->run(['action' => 'health']);
            $status['authenticated'] = (bool) ($result['authenticated'] ?? false);
            $status['page_url'] = $result['page_url'] ?? null;
        } catch (\Throwable $exception) {
            $status['error'] = $exception->getMessage();
        }

        return $status;
    }

    private function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
