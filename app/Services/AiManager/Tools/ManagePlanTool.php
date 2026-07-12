<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use App\Services\AiManager\AiManagerException;
use Illuminate\Support\Facades\DB;

class ManagePlanTool extends AiTool
{
    /** Safety cap on rows touched by one bulk_update proposal. */
    private const MAX_BULK_ROWS = 1000;

    public function name(): string
    {
        return 'manage_plan';
    }

    public function description(): string
    {
        return 'Propose creating, updating, or deleting a service plan in one of the allowed plan catalog tables, or bulk_update to apply the same attribute change to many rows at once (by explicit ids or an id_from/id_to range). Use search_plans first to inspect columns, identify existing rows, and confirm an id range actually has that many rows via total_matches. This creates a pending action that an admin must approve.';
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
                'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'bulk_update']],
                'table' => ['type' => 'string', 'enum' => PlanCatalog::tableNames()],
                'id' => ['type' => 'integer', 'description' => 'Required for update and delete.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Explicit row ids for bulk_update. Alternative to id_from/id_to.'],
                'id_from' => ['type' => 'integer', 'description' => 'Start of an inclusive id range for bulk_update. Requires id_to. Verify with search_plans first that this range actually matches rows — a range matching zero rows fails.'],
                'id_to' => ['type' => 'integer', 'description' => 'End of an inclusive id range for bulk_update.'],
                'attributes' => ['type' => 'object', 'description' => 'Column values to create, update, or bulk_update. Use real table column names only.'],
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
            'action' => 'required|in:create,update,delete,bulk_update',
            'table' => 'required|in:' . implode(',', PlanCatalog::tableNames()),
            'id' => 'nullable|integer|min:1',
            'ids' => 'nullable|array|max:' . self::MAX_BULK_ROWS,
            'ids.*' => 'integer|min:1',
            'id_from' => 'nullable|integer|min:1',
            'id_to' => 'nullable|integer|min:1|gte:id_from',
            'attributes' => 'nullable|array',
            'providerable' => 'nullable|array',
            'use_provider_as_providerable' => 'nullable|boolean',
        ];
    }

    public function summarize(array $arguments): string
    {
        $action = $arguments['action'];
        $table = $arguments['table'];

        if ($action === 'bulk_update') {
            $scope = !empty($arguments['ids'])
                ? count($arguments['ids']) . ' selected id(s)'
                : "ids {$arguments['id_from']}–{$arguments['id_to']}";

            $attrs = collect($arguments['attributes'] ?? [])
                ->map(fn ($v, $k) => "{$k}=" . (is_bool($v) ? ($v ? 'true' : 'false') : $v))
                ->implode(', ');

            return "Bulk update {$table} ({$scope}): set {$attrs}";
        }

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

        if ($action === 'bulk_update') {
            $result = $this->handleBulkUpdate($arguments, $table, $modelClass);
            PlanCatalog::clearCache();

            return $result;
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

            $attributes = PlanCatalog::cleanAttributes($table, $arguments['attributes'] ?? []);

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

    /**
     * Apply the same attribute change to every row matched by an explicit
     * `ids` list or an `id_from`/`id_to` range. Each row goes through
     * forceFill + save (not a raw mass UPDATE) so casts — e.g. a JSON
     * `pricing` column — serialise correctly, same as the single-row path.
     */
    private function handleBulkUpdate(array $arguments, string $table, string $modelClass): array
    {
        $ids = $this->resolveBulkIds($arguments, $modelClass);

        if (empty($ids)) {
            throw new AiManagerException('No plan rows matched the given ids or id range.');
        }

        if (count($ids) > self::MAX_BULK_ROWS) {
            throw new AiManagerException(
                'That range matches ' . count($ids) . ' rows, which is more than the ' . self::MAX_BULK_ROWS . '-row limit for one bulk action. Narrow the range.'
            );
        }

        $attributes = PlanCatalog::cleanAttributes($table, $arguments['attributes'] ?? []);

        if (empty($attributes)) {
            throw new AiManagerException('Bulk update requires at least one valid attribute to set.');
        }

        $updatedIds = DB::transaction(function () use ($modelClass, $ids, $attributes) {
            $plans = $modelClass::whereIn('id', $ids)->get();

            foreach ($plans as $plan) {
                $plan->forceFill($attributes);
                $plan->save();
            }

            return $plans->pluck('id')->all();
        });

        return [
            'bulk_updated' => true,
            'table' => $table,
            'matched' => count($ids),
            'updated' => count($updatedIds),
            'attributes' => $attributes,
            'ids' => $updatedIds,
        ];
    }

    /** @return array<int, int> */
    private function resolveBulkIds(array $arguments, string $modelClass): array
    {
        if (!empty($arguments['ids'])) {
            return $modelClass::whereIn('id', $arguments['ids'])->pluck('id')->all();
        }

        if (isset($arguments['id_from']) && isset($arguments['id_to'])) {
            return $modelClass::whereBetween('id', [$arguments['id_from'], $arguments['id_to']])->pluck('id')->all();
        }

        throw new AiManagerException('bulk_update requires either "ids" or both "id_from" and "id_to".');
    }
}
