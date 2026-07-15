<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for writing the audit trail. Resolves the acting admin
 * from the request, snapshots their identity, attaches request metadata, and
 * redacts secrets before anything is persisted.
 *
 * Never throws into the caller: an audit write must not be able to fail a real
 * admin action, so everything here is best-effort and logged on failure.
 */
class AuditLogger
{
    /** Attribute names whose values must never be stored in the trail. */
    private const REDACT = [
        'password', 'password_confirmation', 'remember_token', 'secret_key',
        'api_key', 'public_key', 'private_key', 'encryption_key', 'webhook_access',
        'token', 'access_token', 'refresh_token', 'secret', 'bvn', 'pin',
        'transaction_pin', 'otp', 'cvv',
    ];

    /** When false, no entries are written — used around bulk/system imports. */
    private static bool $enabled = true;

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    /** Run $callback with auditing suppressed (e.g. a vendor plan sync). */
    public static function muted(callable $callback): mixed
    {
        $previous = self::$enabled;
        self::$enabled = false;
        try {
            return $callback();
        } finally {
            self::$enabled = $previous;
        }
    }

    /**
     * Write one entry. Best-effort — returns null (never throws) on failure.
     *
     * @param array<string, array{old: mixed, new: mixed}>|array<string, mixed>|null $changes
     * @param array<string, mixed> $context
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?array $changes = null,
        ?string $description = null,
        array $context = [],
        ?string $subjectLabel = null,
        ?User $actor = null,
    ): ?AuditLog {
        if (!self::$enabled) {
            return null;
        }

        try {
            $actor ??= self::currentActor();
            $request = request();

            return AuditLog::create([
                'user_id' => $actor?->id,
                'actor_name' => $actor?->fullname ?? $actor?->name ?? ($actor ? null : 'System'),
                'actor_email' => $actor?->email,
                'action' => $action,
                'auditable_type' => $subject ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'subject_label' => $subjectLabel ?? ($subject ? self::labelFor($subject) : null),
                'description' => $description,
                'changes' => $changes !== null ? self::redact($changes) : null,
                // Redacted too: callers pass arbitrary payloads here (AI tool
                // arguments, request context), which can carry credentials just
                // as easily as a changed column can.
                'context' => $context !== [] ? self::redact($context) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLogger: failed to write entry', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Replace the value of any sensitive key with a mask, at any nesting depth,
     * including inside {old,new} diff pairs.
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecret($key)) {
                $data[$key] = is_array($value)
                    ? array_map(fn ($v) => $v === null ? null : '••••••', $value)
                    : '••••••';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private static function isSecret(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::REDACT as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** A human label for a subject: its own accessor, then common columns. */
    public static function labelFor(Model $model): string
    {
        if (method_exists($model, 'auditLabel')) {
            return (string) $model->auditLabel();
        }

        foreach (['name', 'title', 'plan_name', 'email', 'slug', 'label'] as $column) {
            $value = $model->getAttribute($column);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($model) . ' #' . $model->getKey();
    }

    private static function currentActor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
