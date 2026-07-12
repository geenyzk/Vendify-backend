<?php

namespace App\Services\AiManager\Tools;

use App\Models\WelcomeMessage;
use App\Models\User;

class GetWelcomeMessageTool extends AiTool
{
    public function name(): string
    {
        return 'get_welcome_message';
    }

    public function description(): string
    {
        return 'Get the current configured welcome message shown to users on the VTU frontend, including whether it is active.';
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
        $message = WelcomeMessage::first();

        return ['welcome_message' => $message ? $message->toArray() : null];
    }
}
