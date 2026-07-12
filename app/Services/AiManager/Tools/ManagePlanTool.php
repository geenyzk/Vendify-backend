<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use App\Services\AiManager\AiManagerException;
use Illuminate\Support\Facades\DB;

class ManagePlanTool extends AiTool
{
    public function name(): string
    {
        return 'manage_plan';
    }

    public function description(): string
    {
        return 'Propose creating, updating, or deleting a service plan in one of the allowed plan catalog tables. Use search_plans first to inspect columns and identify existing rows. This creates a pending action that an admin must approve.';
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
                'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                'table' => ['type' => 'string', 'enum' => PlanCatalog::tableNames()],
                'id' => ['type' => 'integer', 'description' => 'Required for update and delete.'],
                'attributes' => ['type' => 'object', 'description' => 'Column values to create or update. Use real table column names only.'],
                'providerable' => [
                    'type' => 'object',
                    'description' => 'Optional provider pivot data for plans that support providers: provider_id, cost_price, margin_value, margin_type, server_id.',
                ],
                'use_provider_as_providerable' => [
                    'type' => 'boolean',
                    'description' => 'Whether this plan should use the providerable provider_id. False keeps provider_id null but can store server/cost data.',
                ],
            ],
            'required' => ['action', 'table'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:create,update,delete',
            'table' => 'required|in:' . implode(',', PlanCatalog::tableNames()),
            'id' => 'nullable|integer|min:1',
            'attributes' => 'nullable|array',
            'providerable' => 'nullable|array',
            'use_provider_as_providerable' => 'nullable|boolean',
        ];
    }

    public function summarize(array $arguments): string
    {
        $action = $arguments['action'];
        $table = $arguments['table'];
        $id = isset($arguments['id']) ? " #{$arguments['id']}" : '';

        return ucfirst($action) . " {$table}{$id}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $action = $arguments['action'];
        $table = $arguments['table'];
        $modelClass = PlanCatalog::modelClass($table);

        if (!$modelClass) {
            throw new AiManagerException('Unsupported plan table.');
        }

        if (in_array($action, ['update', 'delete'], true) && empty($arguments['id'])) {
            throw new AiManagerException('Plan id is required for update and delete actions.');
        }

        $result = DB::transaction(function () use ($arguments, $action, $table, $modelClass) {
            if ($action === 'delete') {
                $plan = $modelClass::find($arguments['id']);
                if (!$plan) {
                    throw new AiManagerException('Plan not found.');
                }

                $snapshot = $plan->toArray();
                if (method_exists($plan, 'providers')) {
                    $plan->providers()->detach();
                }
                $plan->delete();

                return [
                    'deleted' => true,
                    'table' => $table,
                    'id' => $arguments['id'],
                    'previous' => $snapshot,
                ];
            }

            $attributes = PlanCatalog::cleanAttributes(
                $table,
                $arguments['attributes'] ?? [],
                $action === 'update',
            );

            if (empty($attributes) && !isset($arguments['providerable']) && !array_key_exists('use_provider_as_providerable', $arguments)) {
                throw new AiManagerException('No valid plan fields were provided.');
            }

            $plan = $action === 'update'
                ? $modelClass::find($arguments['id'])
                : new $modelClass();

            if (!$plan) {
                throw new AiManagerException('Plan not found.');
            }

            if (!empty($attributes)) {
                $plan->forceFill($attributes);

                if ($action === 'update' && in_array('is_draft', PlanCatalog::columns($table), true) && $plan->is_draft) {
                    $plan->is_draft = false;
                }

                $plan->save();
            } elseif (!$plan->exists) {
                throw new AiManagerException('Create actions require valid plan fields.');
            }

            PlanCatalog::syncProviderable($plan, $arguments);

            if (method_exists($plan, 'providers')) {
                $plan->load('providers');
            }

            return [
                'saved' => true,
                'action' => $action,
                'table' => $table,
                'plan' => $plan->fresh()?->toArray() ?? $plan->toArray(),
            ];
        });

        PlanCatalog::clearCache();

        return $result;
    }
}
