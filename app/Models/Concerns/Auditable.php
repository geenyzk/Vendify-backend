<?php

namespace App\Models\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete on a model to the audit trail with a field-level
 * old→new diff. Attach to admin-managed models (plans, providers, roles,
 * settings, …) — every write through the Universal Table API or a controller
 * uses Eloquent, so those all flow through here automatically.
 *
 * A model may declare `protected array $auditExclude = [...]` to keep noisy or
 * automated columns (wallet balances, cached vendor balances, heartbeats) out
 * of the trail. When an update touches only excluded columns the diff is empty
 * and nothing is logged, so background churn never floods the log.
 *
 * Secret values are redacted centrally by AuditLogger, so credentials never
 * reach the table even if a secret column changes.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            AuditLogger::record(
                'created',
                subject: $model,
                changes: $model->auditableSnapshot(),
                description: static::auditDescription('created', $model),
            );
        });

        static::updated(function (Model $model): void {
            $diff = $model->auditableDiff();
            if ($diff === []) {
                return; // Only excluded/unchanged columns moved — not worth a row.
            }
            AuditLogger::record(
                'updated',
                subject: $model,
                changes: $diff,
                description: static::auditDescription('updated', $model),
            );
        });

        static::deleted(function (Model $model): void {
            AuditLogger::record(
                'deleted',
                subject: $model,
                changes: $model->auditableSnapshot(),
                description: static::auditDescription('deleted', $model),
            );
        });
    }

    /** Columns never recorded in a diff or snapshot for this model. */
    protected function auditExcluded(): array
    {
        return array_merge(
            ['created_at', 'updated_at', 'remember_token', 'last_seen_at'],
            $this->auditExclude ?? [],
        );
    }

    /** Meaningful current attributes, for create/delete rows. */
    public function auditableSnapshot(): array
    {
        $excluded = $this->auditExcluded();

        return collect($this->getAttributes())
            ->reject(fn ($_, $key) => in_array($key, $excluded, true))
            ->all();
    }

    /**
     * old→new for every changed, non-excluded column of the just-saved update.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function auditableDiff(): array
    {
        $excluded = $this->auditExcluded();
        $diff = [];

        foreach ($this->getChanges() as $key => $new) {
            if (in_array($key, $excluded, true)) {
                continue;
            }
            $diff[$key] = ['old' => $this->getOriginal($key), 'new' => $new];
        }

        return $diff;
    }

    protected static function auditDescription(string $action, Model $model): string
    {
        $type = class_basename($model);
        $label = AuditLogger::labelFor($model);

        return match ($action) {
            'created' => "Created {$type}: {$label}",
            'updated' => "Updated {$type}: {$label}",
            'deleted' => "Deleted {$type}: {$label}",
            default => ucfirst($action) . " {$type}: {$label}",
        };
    }
}
