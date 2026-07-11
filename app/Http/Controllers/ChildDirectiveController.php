<?php

namespace App\Http\Controllers;

use App\Models\ChildDirective;
use App\Models\ChildInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The pull/ack half of the parent<->child channel — the child polls this
 * on its own cron cadence rather than being pushed to in real time, since
 * it has no persistent process to receive a push. Phase 1 ships the
 * plumbing only; no directive type is actually produced yet (Phase 2).
 */
class ChildDirectiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ChildInstance $instance */
        $instance = $request->attributes->get('childInstance');

        $directives = ChildDirective::where('child_instance_id', $instance->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'type', 'payload', 'created_at']);

        return $this->success($directives);
    }

    public function ack(Request $request, string $id): JsonResponse
    {
        /** @var ChildInstance $instance */
        $instance = $request->attributes->get('childInstance');

        // Route parameters arrive as strings; on PHP 8.x under the framework's
        // strict-typed dispatcher a numeric string is NOT coerced to an `int`
        // parameter — it throws a TypeError (500) before the method body runs.
        // Accept it as a string and cast for the lookup.
        $directive = ChildDirective::where('child_instance_id', $instance->id)->find((int) $id);
        if (!$directive) {
            return $this->fail([], 'Directive not found', 404);
        }

        // Phase 2 children report the real outcome in the (signed) ack body:
        // {"result": "executed"|"failed"|"skipped", "note": "..."}. A legacy
        // child acks with an empty body — keep recording that as 'delivered'
        // (fetched and acted on, outcome unknown). getContent() is used
        // (not $request->input()) because HMAC verification already pinned
        // these exact bytes.
        $body = json_decode($request->getContent(), true);
        $result = is_array($body) && in_array($body['result'] ?? null, ['executed', 'failed', 'skipped'], true)
            ? $body['result']
            : 'delivered';
        $note = is_array($body) && isset($body['note']) && $body['note'] !== null
            ? mb_substr((string) $body['note'], 0, 1000)
            : null;

        $directive->update([
            'status' => $result,
            'delivered_at' => now(),
            'executed_at' => $result === 'executed' ? now() : null,
            'result_note' => $note,
        ]);

        return $this->success(null, 'Acknowledged');
    }
}
