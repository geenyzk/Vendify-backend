<?php

namespace App\Services\AiManager\Tools;

use App\Models\ServiceControl;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

/**
 * Enable or disable a service control (e.g. turn a network's airtime off during
 * an outage) — mirrors ServiceControlController::update. A dev-locked control
 * cannot be toggled from here. Mutating: proposal-only, re-checks `settings`.
 */
class ToggleServiceControlTool extends AiTool
{
    public function name(): string
    {
        return 'toggle_service_control';
    }

    public function description(): string
    {
        return 'Propose enabling or disabling a service control — for example turning off a network\'s airtime or data during a vendor outage. Use list_service_controls context from get_site data if needed; identify the control by its id. Dev-locked controls cannot be changed. Creates a pending action for admin approval.';
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
                'service_control_id' => ['type' => 'integer', 'description' => 'Numeric id of the service control to toggle.'],
                'is_active' => ['type' => 'boolean', 'description' => 'true to enable the service, false to disable it.'],
            ],
            'required' => ['service_control_id', 'is_active'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'service_control_id' => 'required|integer',
            'is_active' => 'required|boolean',
        ];
    }

    public function summarize(array $arguments): string
    {
        $verb = $arguments['is_active'] ? 'Enable' : 'Disable';
        return "{$verb} service control #{$arguments['service_control_id']}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $control = ServiceControl::find($arguments['service_control_id']);

        if (!$control) {
            throw new AiManagerException('Service control not found.');
        }
        if ($control->isDevLock) {
            throw new AiManagerException('This service control is dev-locked and cannot be toggled.');
        }

        $control->isActive = (bool) $arguments['is_active'];
        $control->save();

        return [
            'updated' => true,
            'service_control_id' => $control->id,
            'name' => $control->name,
            'is_active' => (bool) $control->isActive,
        ];
    }
}
