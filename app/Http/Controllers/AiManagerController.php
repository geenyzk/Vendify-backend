<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\AiActionProposal;
use App\Models\AiAlert;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AiManager\AiManagerException;
use App\Services\AiManager\AiManagerService;
use App\Services\AiManager\Tools\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group AI Manager
 *
 * In-app admin assistant. Read-only tools run automatically to ground the
 * assistant's answers in live site data; anything that changes state is held as
 * a pending proposal (see AiActionProposal) that an admin must explicitly
 * approve. Every endpoint is gated by the `ai_manager` permission; approving an
 * action additionally re-checks the permission the action itself requires.
 */
class AiManagerController extends Controller
{
    use HttpResponse;

    public function __construct(
        private readonly AiManagerService $service,
        private readonly ToolRegistry $registry,
    ) {
    }

    /** List the signed-in admin's conversations, most recently active first. */
    public function index(Request $request): JsonResponse
    {
        $conversations = AiConversation::where('user_id', $request->user()->id)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'title', 'last_activity_at', 'created_at']);

        return $this->success($conversations);
    }

    /** Start a new conversation, optionally sending a first message. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'nullable|string|max:4000',
        ]);

        if (!empty($validated['message']) && ($limited = $this->dailyLimitResponse())) {
            return $limited;
        }

        $conversation = $this->service->startConversation($request->user());

        if (!empty($validated['message'])) {
            try {
                $this->service->sendMessage($conversation, $request->user(), $validated['message']);
            } catch (AiManagerException $e) {
                return $this->fail(['conversation_id' => $conversation->id], $e->getMessage(), 502);
            }
        }

        return $this->success($this->conversationPayload($conversation->fresh()), 'Conversation started', 201);
    }

    /**
     * Platform-wide AI usage for today against the configured daily cap, so the
     * UI can show "N of M used" and warn as the limit approaches. The cap bounds
     * OpenAI spend; 0 means unlimited.
     */
    public function usage(): JsonResponse
    {
        return $this->success($this->dailyUsage());
    }

    /** @return array{used:int, limit:int, remaining:int, unlimited:bool, resets_at:string} */
    private function dailyUsage(): array
    {
        $limit = (int) config('services.openai.daily_message_limit', 0);
        $used = AiMessage::where('role', AiMessage::ROLE_USER)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit > 0 ? max(0, $limit - $used) : -1,
            'unlimited' => $limit <= 0,
            'resets_at' => now()->endOfDay()->toDateTimeString(),
        ];
    }

    /** A 429 response when today's cap is reached, or null when there's headroom. */
    private function dailyLimitResponse(): ?JsonResponse
    {
        $usage = $this->dailyUsage();
        if ($usage['unlimited'] || $usage['remaining'] > 0) {
            return null;
        }

        return $this->fail(
            $usage,
            "The daily AI usage limit of {$usage['limit']} messages has been reached. It resets at midnight.",
            429,
        );
    }

    /** Full conversation: messages plus pending/decided action proposals. */
    public function show(Request $request, $id): JsonResponse
    {
        $conversation = $this->ownedConversation($request, $id);
        if (!$conversation) {
            return $this->fail([], 'Conversation not found', 404);
        }

        return $this->success($this->conversationPayload($conversation));
    }

    /** Send a message and get the assistant's reply plus any new proposals. */
    public function sendMessage(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        $conversation = $this->ownedConversation($request, $id);
        if (!$conversation) {
            return $this->fail([], 'Conversation not found', 404);
        }

        if ($limited = $this->dailyLimitResponse()) {
            return $limited;
        }

        try {
            $this->service->sendMessage($conversation, $request->user(), $validated['message']);
        } catch (AiManagerException $e) {
            return $this->fail([], $e->getMessage(), 502);
        }

        return $this->success($this->conversationPayload($conversation->fresh()));
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $conversation = $this->ownedConversation($request, $id);
        if (!$conversation) {
            return $this->fail([], 'Conversation not found', 404);
        }

        $hasExecutedAction = $conversation->proposals
            ->contains(fn (AiActionProposal $p) => $p->status === AiActionProposal::STATUS_EXECUTED);

        if ($hasExecutedAction) {
            return $this->fail([], 'This conversation has executed actions and is kept as an audit record. It cannot be deleted.', 422);
        }

        $conversation->delete();

        return $this->success([], 'Conversation deleted');
    }

    /** Approve and execute a pending action proposed by the assistant. */
    public function approveAction(Request $request, int $id): JsonResponse
    {
        $proposal = $this->ownedProposal($request, $id);
        if (!$proposal) {
            return $this->fail([], 'Action not found', 404);
        }

        try {
            $proposal = $this->service->approve($proposal, $request->user());
        } catch (AiManagerException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }

        return $this->success($this->proposalPayload($proposal), 'Action approved and executed');
    }

    /** Reject a pending action so it can never run. */
    public function rejectAction(Request $request, int $id): JsonResponse
    {
        $proposal = $this->ownedProposal($request, $id);
        if (!$proposal) {
            return $this->fail([], 'Action not found', 404);
        }

        try {
            $proposal = $this->service->reject($proposal, $request->user());
        } catch (AiManagerException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }

        return $this->success($this->proposalPayload($proposal), 'Action rejected');
    }

    private function ownedConversation(Request $request, $identifier): ?AiConversation
    {
        $query = AiConversation::with(['messages', 'proposals'])
            ->where('user_id', $request->user()->id);

        if (is_numeric($identifier)) {
            return $query->where('id', (int) $identifier)->first();
        }

        return $query->where('uuid', $identifier)->first();
    }

    private function ownedProposal(Request $request, int $id): ?AiActionProposal
    {
        return AiActionProposal::with('conversation')->whereHas(
            'conversation',
            fn ($q) => $q->where('user_id', $request->user()->id)
        )->find($id);
    }

    /**
     * Unacknowledged monitoring alerts, written by the AiMonitor middleware's
     * background health sweeps. Most severe and most recently confirmed first.
     */
    public function alerts(): JsonResponse
    {
        $alerts = AiAlert::unacknowledged()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'severity', 'title', 'created_at', 'updated_at']);

        return $this->success($alerts);
    }

    /** Dismiss one alert. It can reappear if the monitor re-detects the condition. */
    public function acknowledgeAlert(int $id): JsonResponse
    {
        $alert = AiAlert::unacknowledged()->find($id);
        if (!$alert) {
            return $this->fail([], 'Alert not found.', 404);
        }

        $alert->update(['acknowledged_at' => now()]);

        return $this->success([], 'Alert acknowledged');
    }

    /** Dismiss every open alert at once. */
    public function acknowledgeAllAlerts(): JsonResponse
    {
        AiAlert::unacknowledged()->update(['acknowledged_at' => now()]);

        return $this->success([], 'All alerts acknowledged');
    }

    private function conversationPayload(AiConversation $conversation): array
    {
        $conversation->loadMissing(['messages', 'proposals']);

        $messages = $conversation->messages
            // Drop the raw tool RESULT plumbing (ROLE_TOOL) and any truly empty
            // row, but keep assistant messages that only carry tool_calls so the
            // UI can show a subtle "looked up X" activity line for transparency.
            ->reject(fn (AiMessage $m) => $m->role === AiMessage::ROLE_TOOL
                || (empty($m->content) && empty($this->toolNames($m))))
            ->map(fn (AiMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'tools' => $this->toolNames($m),
                'created_at' => $m->created_at?->toDateTimeString(),
            ])
            ->values();

        return [
            'id' => $conversation->id,
            'uuid' => $conversation->uuid ?? null,
            'title' => $conversation->title,
            'last_activity_at' => $conversation->last_activity_at?->toDateTimeString(),
            'messages' => $messages,
            'proposals' => $conversation->proposals
                ->sortByDesc('id')
                ->map(fn (AiActionProposal $p) => $this->proposalPayload($p))
                ->values(),
        ];
    }

    /**
     * Tool names an assistant message called this turn, for the UI's activity
     * line. Empty for user/system messages and assistant messages with no calls.
     *
     * @return array<int, string>
     */
    private function toolNames(AiMessage $m): array
    {
        if ($m->role !== AiMessage::ROLE_ASSISTANT || empty($m->tool_calls)) {
            return [];
        }

        $calls = is_array($m->tool_calls) ? $m->tool_calls : (json_decode($m->tool_calls, true) ?: []);

        return array_values(array_filter(array_map(
            fn ($c) => is_array($c) ? ($c['name'] ?? null) : null,
            $calls,
        )));
    }

    private function proposalPayload(AiActionProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'conversation_id' => $proposal->ai_conversation_id,
            'tool' => $proposal->tool,
            'permission' => $proposal->permission,
            'summary' => $proposal->summary,
            'arguments' => $proposal->arguments,
            'status' => $proposal->status,
            'result' => $proposal->result,
            'error' => $proposal->error,
            'decided_at' => $proposal->decided_at?->toDateTimeString(),
            'executed_at' => $proposal->executed_at?->toDateTimeString(),
            'created_at' => $proposal->created_at?->toDateTimeString(),
        ];
    }
}
