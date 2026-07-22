<?php

namespace App\Services\AiManager;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class VendifyDataPlanBrowser
{
    public function run(array $command): array
    {
        if (! config('services.vendify_browser.enabled')) {
            throw new AiManagerException('Vendify browser automation is disabled.');
        }

        $baseUrl = rtrim((string) config('services.vendify_browser.base_url'), '/');
        $state = (string) config('services.vendify_browser.storage_state');
        $state = $state !== '' && ! str_starts_with($state, DIRECTORY_SEPARATOR)
            ? base_path($state)
            : $state;

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new AiManagerException('Vendify browser base URL is not configured safely.');
        }
        if ($state === '' || ! is_file($state)) {
            throw new AiManagerException('Vendify browser authentication state is missing.');
        }

        $runId = now()->format('Ymd-His').'-'.Str::lower(Str::random(8));
        $artifactDir = storage_path('app/private/ai-browser/'.$runId);
        if (! is_dir($artifactDir) && ! mkdir($artifactDir, 0750, true) && ! is_dir($artifactDir)) {
            throw new AiManagerException('Could not create the browser evidence directory.');
        }

        $payload = [
            'baseUrl' => $baseUrl,
            'allowedOrigins' => array_values(array_unique(array_merge([$baseUrl], config('services.vendify_browser.allowed_origins', [])))),
            'storageState' => $state,
            'artifactDir' => $artifactDir,
            'headless' => (bool) config('services.vendify_browser.headless', true),
            'command' => $command,
        ];

        $result = Process::timeout((int) config('services.vendify_browser.timeout', 90))
            ->input(json_encode($payload, JSON_THROW_ON_ERROR))
            ->run([
                (string) config('services.vendify_browser.node_binary', 'node'),
                base_path('browser/vendify-data-plans.mjs'),
            ]);

        if (! $result->successful()) {
            throw new AiManagerException('Vendify browser run failed: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 1200));
        }

        $decoded = json_decode(trim($result->output()), true);
        if (! is_array($decoded) || ! ($decoded['ok'] ?? false)) {
            throw new AiManagerException('Vendify browser returned an invalid result.');
        }

        $decoded['run_id'] = $runId;
        $decoded['artifacts'] = collect($decoded['artifacts'] ?? [])
            ->map(fn (string $path) => 'storage/app/private/ai-browser/'.$runId.'/'.basename($path))
            ->all();

        return $decoded;
    }
}
