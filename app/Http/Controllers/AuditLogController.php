<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of the audit trail. There is deliberately no create/update/
 * delete endpoint — the log is append-only so it cannot be tampered with from
 * the UI. Writes happen only through App\Support\AuditLogger, and retention is
 * handled by the scheduled `audit:prune` command.
 *
 * @group Audit Log
 */
class AuditLogController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /**
     * Paginated, filterable trail (newest first).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'nullable|string|max:60',
            'user_id' => 'nullable|integer',
            'subject_type' => 'nullable|string|max:120',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
        ]);

        $query = AuditLog::query()->with('user:id,fullname,email');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        // The UI filters by the short class name ("DataPlan"); match the tail of
        // the stored FQCN so callers never deal in namespaces.
        if ($type = $request->query('subject_type')) {
            $query->where('auditable_type', 'like', '%\\\\' . $type);
        }

        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('description', 'like', "%{$q}%")
                    ->orWhere('subject_label', 'like', "%{$q}%")
                    ->orWhere('actor_name', 'like', "%{$q}%")
                    ->orWhere('actor_email', 'like', "%{$q}%");
            });
        }

        $logs = $query->latest('id')
            ->paginate((int) $request->query('per_page', 25));

        return $this->success([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => $this->payload($log))->all(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Distinct values for the filter dropdowns, so the UI doesn't have to
     * derive them from one page of results.
     */
    public function filters(): JsonResponse
    {
        $actions = AuditLog::query()
            ->select('action')->distinct()->orderBy('action')->pluck('action');

        $types = AuditLog::query()
            ->whereNotNull('auditable_type')
            ->select('auditable_type')->distinct()->pluck('auditable_type')
            ->map(fn ($type) => class_basename($type))
            ->unique()->sort()->values();

        $actors = AuditLog::query()
            ->whereNotNull('user_id')
            ->select('user_id', 'actor_name')
            ->distinct()
            ->orderBy('actor_name')
            ->get()
            ->map(fn ($row) => ['id' => $row->user_id, 'name' => $row->actor_name])
            ->unique('id')
            ->values();

        return $this->success([
            'actions' => $actions,
            'subject_types' => $types,
            'actors' => $actors,
        ]);
    }

    private function payload(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->auditable_id,
            'subject_label' => $log->subject_label,
            'changes' => $log->changes,
            'context' => $log->context,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->toDateTimeString(),
            'actor' => [
                'id' => $log->user_id,
                // Prefer the live user record, falling back to the snapshot so
                // deleted/renamed accounts still read correctly.
                'name' => $log->user?->fullname ?? $log->actor_name ?? 'System',
                'email' => $log->user?->email ?? $log->actor_email,
            ],
        ];
    }
}
