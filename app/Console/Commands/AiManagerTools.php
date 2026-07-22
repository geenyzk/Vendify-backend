<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AiManager\AiManagerToolDiagnostics;
use Illuminate\Console\Command;

class AiManagerTools extends Command
{
    protected $signature = 'ai-manager:tools {--user= : Admin email or numeric user id} {--no-health : Skip the live browser probe}';
    protected $description = 'Display AI Manager tool registration, availability, permissions and browser health';

    public function handle(AiManagerToolDiagnostics $diagnostics): int
    {
        $actor = $this->resolveActor($this->option('user'));
        $report = $diagnostics->report($actor, ! $this->option('no-health'));

        $this->components->info('AI Manager tool diagnostics');
        $this->line('Environment: '.$report['environment']);
        $this->line('Config cached: '.($report['configuration_cached'] ? 'yes' : 'no'));
        $this->line('VENDIFY_BROWSER_ENABLED: '.($report['feature_flag'] ? 'true' : 'false'));
        $this->line('Registry binding: '.($report['registry_bound'] ? 'yes' : 'no'));
        $this->line('Browser service binding: '.($report['browser_service_bound'] ? 'yes' : 'no'));
        $this->line('Manifest loading: '.$report['manifest_loading']);
        $this->line('Actor: '.($actor ? "{$actor->email} ({$actor->role?->slug})" : 'not resolved; permission result omitted'));

        $this->table(
            ['Tool', 'Registered', 'Enabled', 'Reason', 'Permission', 'Permitted', 'Model'],
            collect($report['tools'])->map(fn (array $tool) => [
                $tool['name'], 'yes', $tool['enabled'] ? 'yes' : 'no', $tool['reason'] ?? '—',
                $tool['permission'] ?? '—', $tool['permitted'] === null ? 'n/a' : ($tool['permitted'] ? 'yes' : 'no'),
                $tool['exposed_to_model'] === null ? 'n/a' : ($tool['exposed_to_model'] ? 'yes' : 'no'),
            ])->all(),
        );

        $health = $report['browser_health'];
        $this->newLine();
        $this->components->info('Browser health');
        foreach ([
            'state_exists' => 'State exists', 'state_readable' => 'State readable',
            'node_available' => 'Node available', 'playwright_installed' => 'Playwright installed',
            'runner_exists' => 'Runner exists', 'authenticated' => 'Authenticated',
        ] as $key => $label) {
            $this->line("{$label}: ".($health[$key] ? 'yes' : 'no'));
        }
        if (isset($health['page_url'])) $this->line('Page URL: '.$health['page_url']);
        if (isset($health['error'])) $this->components->error($health['error']);

        $browserToolsReady = collect($report['tools'])
            ->whereIn('name', ['inspect_vendify_data_plans', 'automate_vendify_data_plan'])
            ->every(fn (array $tool) => $tool['exposed_to_model'] !== false && $tool['enabled']);

        return $report['feature_flag'] && $health['authenticated'] && $browserToolsReady
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function resolveActor(mixed $identifier): ?User
    {
        if ($identifier !== null && $identifier !== '') {
            return User::with('role')->when(
                is_numeric($identifier),
                fn ($query) => $query->whereKey((int) $identifier),
                fn ($query) => $query->where('email', $identifier),
            )->first();
        }

        return User::with('role')->where('user_type', 'admin')->orderBy('id')->first();
    }
}
