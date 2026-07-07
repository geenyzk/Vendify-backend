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

    public function ack(Request $request, int $id): JsonResponse
    {
        /** @var ChildInstance $instance */
        $instance = $request->attributes->get('childInstance');

        $directive = ChildDirective::where('child_instance_id', $instance->id)->find($id);
        if (!$directive) {
            return $this->fail([], 'Directive not found', 404);
        }

        $directive->update(['status' => 'delivered', 'delivered_at' => now()]);

        return $this->success(null, 'Acknowledged');
    }
}
