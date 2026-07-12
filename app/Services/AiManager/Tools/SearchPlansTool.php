<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class SearchPlansTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'search_plans';
    }

    public function description(): string
    {
        return 'Search any service plan catalog table before proposing create, update, or delete actions. Supports data, airtime, cable, bill, exam, airtime PIN, and data PIN plan tables.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'enum' => PlanCatalog::tableNames(),
                    'description' => 'Plan table to inspect.',
                ],
                'query' => ['type' => 'string', 'description' => 'Text to match against name-like columns.'],
                'active_only' => ['type' => 'boolean', 'description' => 'Only return active rows when the table has an active column.'],
                'filters' => ['type' => 'object', 'description' => 'Exact-match filters by real column name, for example {"network":"mtn"} or {"plan_type":"SME"}.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50, default 15).'],
            ],
            'required' => ['table'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'table' => 'required|in:' . implode(',', PlanCatalog::tableNames()),
            'query' => 'nullable|string|max:120',
            'active_only' => 'nullable|boolean',
            'filters' => 'nullable|array',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $table = $arguments['table'];
        $modelClass = PlanCatalog::modelClass($table);
        $model = new $modelClass();
        $realTable = $model->getTable();
        $columns = Schema::getColumnListing($realTable);

        $query = $modelClass::query();

        foreach (($arguments['filters'] ?? []) as $column => $value) {
            if (in_array($column, $columns, true) && $value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        if (!empty($arguments['active_only']) && in_array('active', $columns, true)) {
            $query->where('active', true);
        }

        if (!empty($arguments['query'])) {
            $needle = trim((string) $arguments['query']);
            $searchable = array_values(array_intersect(
                ['plan_name', 'name', 'network', 'cable_network', 'disco', 'plan_type', 'type', 'category'],
                $columns,
            ));

            if ($needle !== '' && !empty($searchable)) {
                $query->where(function ($subQuery) use ($searchable, $needle) {
                    foreach ($searchable as $column) {
                        $subQuery->orWhere($column, 'like', "%{$needle}%");
                    }
                });
            }
        }

        $total = (clone $query)->count();
        $limit = min((int) ($arguments['limit'] ?? 15), self::MAX_LIMIT);
        $orderColumn = in_array('sort_order', $columns, true) ? 'sort_order' : 'id';

        $rows = $query
            ->orderBy($orderColumn)
            ->limit($limit)
            ->get()
            ->map(fn ($plan) => $plan->toArray())
            ->values();

        return [
            'table' => $table,
            'columns' => $columns,
            'total_matches' => $total,
            'returned' => $rows->count(),
            'plans' => $rows,
            'note' => 'Use manage_plan only after identifying the exact table and row. Mutations require explicit admin approval.',
        ];
    }
}
