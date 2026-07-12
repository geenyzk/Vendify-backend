<?php

namespace App\Services\AiManager\Tools;

use App\Models\Setting;
use App\Models\User;

class GetSiteSettingsTool extends AiTool
{
    public function name(): string
    {
        return 'get_site_settings';
    }

    public function description(): string
    {
        return 'Retrieve the current platform site settings for admin review, excluding sensitive fields such as stored mail passwords. Use this before proposing setting changes.';
    }

    public function permission(): ?string
    {
        return 'settings';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function handle(array $arguments, User $actor): array
    {
        $settings = Setting::first();

        return [
            'settings' => $settings ? $settings->toArray() : null,
        ];
    }
}
