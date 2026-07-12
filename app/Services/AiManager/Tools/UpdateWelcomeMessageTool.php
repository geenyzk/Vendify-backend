<?php

namespace App\Services\AiManager\Tools;

use App\Models\WelcomeMessage;
use App\Models\User;

class UpdateWelcomeMessageTool extends AiTool
{
    public function name(): string
    {
        return 'update_welcome_message';
    }

    public function description(): string
    {
        return 'Propose creating or updating the customer-facing welcome message shown in the VTU user interface. Creates a pending action that must be approved before changes go live.';
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
                'body' => ['type' => 'string', 'description' => 'The body text shown to users.'],
                'title' => ['type' => 'string', 'description' => 'Optional title for compatibility; may not be displayed in the current UI.'],
                'active' => ['type' => 'boolean', 'description' => 'Whether the welcome message should be active.'],
            ],
            'required' => ['body'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'body' => 'required|string',
            'title' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
        ];
    }

    public function summarize(array $arguments): string
    {
        return 'Update welcome message' . (isset($arguments['active']) ? ' (' . ($arguments['active'] ? 'active' : 'inactive') . ')' : '');
    }

    public function handle(array $arguments, User $actor): array
    {
        $message = WelcomeMessage::first();
        $data = [
            'body' => $arguments['body'],
            'title' => $arguments['title'] ?? '',
            'active' => $arguments['active'] ?? true,
        ];

        if ($message) {
            $message->update($data);
        } else {
            $message = WelcomeMessage::create($data);
        }

        return [
            'updated' => true,
            'welcome_message' => $message->toArray(),
        ];
    }
}
