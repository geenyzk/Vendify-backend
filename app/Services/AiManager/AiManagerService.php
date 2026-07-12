<?php

namespace App\Services\AiManager;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AiManager\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates one AI Manager turn: replay the transcript to OpenAI, run any
 * read-only tools it calls (feeding results back so it can reason over live
 * data), and turn any mutating tool call into a *pending proposal* instead of
 * executing it. Approval/rejection of those proposals also lives here — that is
 * the only path by which a mutating tool actually runs, and it re-checks the
 * underlying permission before doing so.
 */
class AiManagerService
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly ToolRegistry $registry,
    ) {
    }

    public function startConversation(User $user, ?string $title = null): AiConversation
    {
        return AiConversation::create([
            'user_id' => $user->id,
            'title' => $title,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Run a user turn to completion. Returns the final assistant message and any
     * proposals raised during the turn.
     *
     * @return array{assistant: AiMessage, proposals: array<int, AiActionProposal>}
     */
    public function sendMessage(AiConversation $conversation, User $actor, string $content): array
    {
        $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'content' => $content,
        ]);

        if (empty($conversation->title)) {
            $conversation->title = Str::limit(trim($content), 60);
        }
        $conversation->last_activity_at = now();
        $conversation->save();

        $tools = $this->registry->openAiSchemasForUser($actor);
        $maxIterations = (int) config('services.openai.max_tool_iterations', 6);

        /** @var array<int, AiActionProposal> $newProposals */
        $newProposals = [];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $reply = $this->client->chat($this->buildMessages($conversation, $actor), $tools);

            $toolCalls = $reply['tool_calls'] ?? [];

            $assistantMessage = $conversation->messages()->create([
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => $reply['content'] ?? null,
                'tool_calls' => !empty($toolCalls) ? $toolCalls : null,
            ]);

            // No tool calls means the model produced its final answer.
            if (empty($toolCalls)) {
                return ['assistant' => $assistantMessage, 'proposals' => $newProposals];
            }

            foreach ($toolCalls as $call) {
                $result = $this->handleToolCall($conversation, $actor, $call, $assistantMessage, $newProposals);

                $conversation->messages()->create([
                    'role' => AiMessage::ROLE_TOOL,
                    'tool_call_id' => $call['id'] ?? null,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
            // Loop so the model can react to the tool results.
        }

        // Ran out of tool iterations without a final answer — surface a graceful
        // message rather than looping forever.
        $fallback = $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'I wasn\'t able to finish working through that within the allowed number of steps. Could you narrow the request down?',
        ]);

        return ['assistant' => $fallback, 'proposals' => $newProposals];
    }

    /**
     * Execute one tool call. Read-only tools run inline and return their data;
     * mutating tools are recorded as a pending proposal and return a
     * "needs approval" marker the model can relay to the admin.
     *
     * @param array<int, AiActionProposal> $newProposals
     */
    private function handleToolCall(
        AiConversation $conversation,
        User $actor,
        array $call,
        AiMessage $assistantMessage,
        array &$newProposals,
    ): array {
        $name = $call['function']['name'] ?? '';
        $tool = $this->registry->get($name);

        if (!$tool || !$this->registry->userMayUse($actor, $tool)) {
            return ['error' => "The tool '{$name}' is not available to you."];
        }

        $arguments = json_decode($call['function']['arguments'] ?? '{}', true);
        if (!is_array($arguments)) {
            $arguments = [];
        }

        try {
            $arguments = $tool->validate($arguments);
        } catch (ValidationException $e) {
            return ['error' => 'The arguments were invalid.', 'details' => $e->errors()];
        }

        if (!$tool->isMutating()) {
            try {
                return $tool->handle($arguments, $actor);
            } catch (\Throwable $e) {
                Log::warning('AI Manager: read tool failed', ['tool' => $name, 'error' => $e->getMessage()]);
                return ['error' => $e->getMessage()];
            }
        }

        // Mutating: do NOT run. Record a proposal for human approval.
        $proposal = $conversation->proposals()->create([
            'ai_message_id' => $assistantMessage->id,
            'tool' => $tool->name(),
            'permission' => $tool->permission(),
            'arguments' => $arguments,
            'summary' => $tool->summarize($arguments),
            'status' => AiActionProposal::STATUS_PENDING,
        ]);

        $newProposals[] = $proposal;

        return [
            'status' => 'pending_approval',
            'proposal_id' => $proposal->id,
            'summary' => $proposal->summary,
            'note' => 'This action was NOT executed. It is queued as a pending proposal and will only run after an admin explicitly approves it. Explain to the admin what you are proposing and why, and that they must approve it.',
        ];
    }

    /**
     * Approve and execute a pending proposal. Re-checks that the approver holds
     * the underlying permission (the AI can never let an admin exceed their own
     * rights), then runs the tool and records the outcome as the audit trail.
     */
    public function approve(AiActionProposal $proposal, User $actor): AiActionProposal
    {
        if (!$proposal->isPending()) {
            throw new AiManagerException('This action is no longer pending.');
        }

        $tool = $this->registry->get($proposal->tool);
        if (!$tool || !$tool->isMutating()) {
            throw new AiManagerException('This action can no longer be executed.');
        }

        if (!$this->registry->userMayUse($actor, $tool)) {
            throw new AiManagerException('You do not have permission to approve this action.');
        }

        try {
            $arguments = $tool->validate($proposal->arguments);
            $result = $tool->handle($arguments, $actor);
        } catch (\Throwable $e) {
            $proposal->update([
                'status' => AiActionProposal::STATUS_FAILED,
                'error' => $e->getMessage(),
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'executed_at' => now(),
            ]);
            $this->recordSystemNote($proposal->conversation, "[Action #{$proposal->id} failed] {$proposal->tool}: {$e->getMessage()}");

            throw new AiManagerException($e->getMessage());
        }

        $proposal->update([
            'status' => AiActionProposal::STATUS_EXECUTED,
            'result' => $result,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'executed_at' => now(),
        ]);

        $this->recordSystemNote(
            $proposal->conversation,
            "[Action #{$proposal->id} executed] {$proposal->summary} — approved by {$actor->email}.",
        );

        return $proposal;
    }

    public function reject(AiActionProposal $proposal, User $actor): AiActionProposal
    {
        if (!$proposal->isPending()) {
            throw new AiManagerException('This action is no longer pending.');
        }

        $proposal->update([
            'status' => AiActionProposal::STATUS_REJECTED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        $this->recordSystemNote(
            $proposal->conversation,
            "[Action #{$proposal->id} rejected] {$proposal->summary} — rejected by {$actor->email}.",
        );

        return $proposal;
    }

    /**
     * Full replayable message list for a turn: the system prompt followed by
     * the stored transcript in OpenAI shape. Queried fresh so messages created
     * earlier in this same turn are included.
     *
     * @return array<int, array>
     */
    private function buildMessages(AiConversation $conversation, User $actor): array
    {
        $messages = [[
            'role' => AiMessage::ROLE_SYSTEM,
            'content' => $this->systemPrompt($actor),
        ]];

        foreach ($conversation->messages()->orderBy('id')->get() as $message) {
            $messages[] = $message->toOpenAi();
        }

        return $messages;
    }

    private function recordSystemNote(AiConversation $conversation, string $content): void
    {
        $conversation->messages()->create([
            'role' => AiMessage::ROLE_SYSTEM,
            'content' => $content,
        ]);
    }

    private function systemPrompt(User $actor): string
    {
        $appName = config('app.name', 'the platform');
        $today = now()->toDayDateTimeString();

        return <<<PROMPT
You are the AI Manager for {$appName}, a Nigerian VTU (airtime, data, cable, electricity, exam PIN) and wallet platform. You assist the signed-in administrator ({$actor->fullname}) with monitoring the site and managing operations.

Today is {$today}. All monetary amounts are in Nigerian Naira (NGN).

How you work:
- Be a business-minded manager. Think in terms of profitability, cost, price, customer impact, and operational risk when recommending actions.
- Ground every factual claim in tool results. Call the read tools to look things up rather than guessing. If you don't have data, say so and offer to fetch it.
- Be concise and specific. Prefer exact figures, ids, and references over vague summaries. Format money as "NGN 1,234.56".
- You cannot see anything you have not fetched. Do not invent transactions, users, balances, or statuses.

Taking actions (very important):
- Some tools make changes (refunds, wallet adjustments, status overrides, toggling services, sending broadcasts, editing prices, marketing, affiliates). These tools DO NOT execute when you call them — they create a *pending proposal* that the administrator must explicitly approve in the UI.
- Never tell the admin that an action has been done. Say that you have *proposed* it and that they must approve it. Clearly state what will happen if they approve, including any amounts and who is affected.
- Only propose an action when the admin has asked for it or clearly agreed to it. When unsure, ask first.
- You only ever see the tools the current admin is permitted to use; if a requested action isn't available, tell them they may lack the permission for it.

Keep responses focused and professional.
PROMPT;
    }
}
