<?php

namespace App\Console\Commands;

use App\Services\AiManager\VendifyDataPlanBrowser;
use Illuminate\Console\Command;

class VendifyBrowserHealth extends Command
{
    protected $signature = 'vendify-browser:health';
    protected $description = 'Verify the restricted Vendify Data Plans browser session';

    public function handle(VendifyDataPlanBrowser $browser): int
    {
        $configured = (string) config('services.vendify_browser.storage_state');
        $resolved = $configured !== '' && ! str_starts_with($configured, DIRECTORY_SEPARATOR)
            ? base_path($configured)
            : $configured;

        $this->components->info('Vendify browser health check');
        $this->line('Base URL: '.config('services.vendify_browser.base_url'));
        $this->line('Storage state: '.$resolved);

        if ($resolved === '' || ! is_file($resolved)) {
            $this->components->error('authenticated: no — storage-state file does not exist');
            return self::FAILURE;
        }
        if (! is_readable($resolved)) {
            $this->components->error('authenticated: no — storage-state file is not readable by this process');
            return self::FAILURE;
        }

        try {
            $result = $browser->run(['action' => 'health']);
        } catch (\Throwable $exception) {
            $this->components->error('authenticated: no — '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->components->info('state file exists: yes');
        $this->components->info('state file readable: yes');
        $this->components->info('Data Plans page opened: yes');
        $this->components->info('redirected to login: no');
        $this->components->info('authenticated: yes');
        $this->line('Page URL: '.($result['page_url'] ?? 'unknown'));
        return self::SUCCESS;
    }
}
