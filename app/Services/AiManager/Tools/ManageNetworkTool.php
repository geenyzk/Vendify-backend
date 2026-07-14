<?php

namespace App\Services\AiManager\Tools;

use App\Models\Network;
use App\Models\NetworkType;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use App\Support\PerformanceCache;
use Illuminate\Support\Facades\Schema;

/**
 * Create, update, or delete a carrier network or a network type (per-service
 * category). Mutating: proposal-only, gated by `settings`. Attributes are
 * filtered to real columns so the model can only touch valid fields.
 */
class ManageNetworkTool extends AiTool
{
    private const TARGETS = ['networks', 'network_types'];

    /** Columns never settable directly from a tool call. */
    private const GUARDED = ['id', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'manage_network';
    }

    public function description(): string
    {
        return 'Propose creating, updating, or deleting a network or network type. target "networks" = carriers (name, active, API ids, airtime-to-cash config); target "network_types" = per-service categories (name, service_type data/airtime/cable, active). Inspect existing rows and columns with list_networks first. Creates a pending action an admin must approve.';
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
                'target' => ['type' => 'string', 'enum' => self::TARGETS],
                'id' => ['type' => 'integer', 'description' => 'Required for update and delete.'],
                'attributes' => ['type' => 'object', 'description' => 'Column values to set (create/update). Use real column names from list_networks.'],
            ],
            'required' => ['action', 'target'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:create,update,delete',
            'target' => 'required|in:' . implode(',', self::TARGETS),
            'id' => 'nullable|integer|min:1',
            'attributes' => 'nullable|array',
        ];
    }

    public function summarize(array $arguments): string
    {
        $id = isset($arguments['id']) ? " #{$arguments['id']}" : '';
        $name = $arguments['attributes']['name'] ?? null;
        $label = $name ? " ({$name})" : '';

        return ucfirst($arguments['action']) . " {$arguments['target']}{$id}{$label}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $target = $arguments['target'];
        $modelClass = $target === 'networks' ? Network::class : NetworkType::class;
        $table = (new $modelClass())->getTable();
        $action = $arguments['action'];

        if (in_array($action, ['update', 'delete'], true) && empty($arguments['id'])) {
            throw new AiManagerException('An id is required for update and delete.');
        }

        if ($action === 'delete') {
            $row = $modelClass::find($arguments['id']);
            if (!$row) {
                throw new AiManagerException(ucfirst($target) . ' row not found.');
            }
            $snapshot = $row->toArray();
            $row->delete();
            PerformanceCache::clearCatalog();

            return ['deleted' => true, 'target' => $target, 'id' => $arguments['id'], 'previous' => $snapshot];
        }

        $attributes = $this->cleanAttributes($table, $arguments['attributes'] ?? []);
        if (empty($attributes)) {
            throw new AiManagerException('No valid columns were provided for ' . $target . '.');
        }

        $row = $action === 'update' ? $modelClass::find($arguments['id']) : new $modelClass();
        if (!$row) {
            throw new AiManagerException(ucfirst($target) . ' row not found.');
        }

        $row->forceFill($attributes)->save();
        PerformanceCache::clearCatalog();

        return ['saved' => true, 'action' => $action, 'target' => $target, 'row' => $row->fresh()->toArray()];
    }

    /** Keep only real, non-guarded columns of the table. */
    private function cleanAttributes(string $table, array $attributes): array
    {
        $columns = Schema::getColumnListing($table);

        return array_filter(
            $attributes,
            fn ($key) => in_array($key, $columns, true) && !in_array($key, self::GUARDED, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
