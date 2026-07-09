<?php

namespace App\Services\AiManager\Tools;

use App\Models\ChildDirective;
use App\Models\ChildInstance;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

/**
 * Queue a directive for an affiliate (child) instance to pick up on its next
 * poll — mirrors AdminController::createChildDirective. Mutating: proposal-only.
 * Affiliate management is owner-level surface, so this is gated by `settings`.
 */
class CreateAffiliateDirectiveTool extends AiTool
{
    public function name(): string
    {
        return 'create_affiliate_directive';
    }

    public function description(): string
    {
        return 'Propose queuing a directive (an instruction) for an affiliate instance to apply on its next check-in — for example to push a config change or a message. Identify the affiliate with list_affiliates first. Creates a pending action an admin must approve.';
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
                'affiliate_id' => ['type' => 'integer', 'description' => 'Numeric id of the affiliate (child) instance.'],
                'type' => ['type' => 'string', 'description' => 'Directive type (a short verb the affiliate understands, e.g. "sync", "notice").'],
                'payload' => ['type' => 'object', 'description' => 'Optional structured data for the directive.'],
            ],
            'required' => ['affiliate_id', 'type'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'affiliate_id' => 'required|integer',
            'type' => 'required|string|max:100',
            'payload' => 'nullable|array',
        ];
    }

    public function summarize(array $arguments): string
    {
        return "Queue '{$arguments['type']}' directive for affiliate #{$arguments['affiliate_id']}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $instance = ChildInstance::find($arguments['affiliate_id']);
        if (!$instance) {
            throw new AiManagerException('Affiliate not found.');
        }

        $directive = ChildDirective::create([
            'child_instance_id' => $instance->id,
            'type' => $arguments['type'],
            'payload' => $arguments['payload'] ?? [],
            'status' => 'pending',
        ]);

        return [
            'queued' => true,
            'directive_id' => $directive->id,
            'affiliate_id' => $instance->id,
            'type' => $directive->type,
        ];
    }
}
