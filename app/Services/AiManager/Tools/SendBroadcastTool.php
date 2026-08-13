<?php

namespace App\Services\AiManager\Tools;

use App\Http\Controllers\BroadcastController;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Send a notification/broadcast to customers. Rather than re-implement the
 * audience + multi-channel delivery pipeline, this maps a small set of
 * assistant-friendly arguments onto the exact payload BroadcastController::send
 * expects and invokes it on approval, so behaviour never drifts from the manual
 * Broadcast UI. Mutating: proposal-only, gated by `support`.
 */
class SendBroadcastTool extends AiTool
{
    private const CHANNEL_MAP = [
        'in_app' => 'database',
        'email' => 'Email',
        'sms' => 'sms',
    ];

    public function name(): string
    {
        return 'send_broadcast';
    }

    public function description(): string
    {
        return 'Propose sending a notification to customers — to everyone, to newly signed-up users, or to one specific customer — over in-app, email, and/or SMS. Creates a pending action; the message is only sent after an admin approves it.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'support';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => [
                    'type' => 'string',
                    'enum' => ['all', 'new_users', 'user'],
                    'description' => 'Who receives it: all customers, only users who signed up recently, or one specific user.',
                ],
                'user_email' => ['type' => 'string', 'description' => 'Required when target is "user": the recipient customer\'s email.'],
                'user_id' => ['type' => 'integer', 'description' => 'Alternative to user_email when target is "user".'],
                'signed_up_within_days' => ['type' => 'integer', 'description' => 'Required when target is "new_users": include users who joined within this many days.'],
                'channels' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['in_app', 'email', 'sms']],
                    'description' => 'Delivery channels. Defaults to ["in_app"].',
                ],
                'title' => ['type' => 'string', 'description' => 'Notification title / email subject.'],
                'message' => ['type' => 'string', 'description' => 'Body of the notification. Used for in-app and email (and truncated to 160 chars for SMS).'],
                'priority_high' => ['type' => 'boolean', 'description' => 'Mark the in-app notification as high priority. Default false.'],
            ],
            'required' => ['target', 'title', 'message'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'target' => 'required|in:all,new_users,user',
            'user_email' => 'nullable|email',
            'user_id' => 'nullable|integer',
            'signed_up_within_days' => 'nullable|integer|min:1',
            'channels' => 'nullable|array',
            'channels.*' => 'in:in_app,email,sms',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'priority_high' => 'nullable|boolean',
        ];
    }

    public function summarize(array $arguments): string
    {
        $channels = implode(', ', $arguments['channels'] ?? ['in_app']);
        $who = match ($arguments['target']) {
            'user' => 'one customer (' . ($arguments['user_email'] ?? ('#' . ($arguments['user_id'] ?? '?'))) . ')',
            'new_users' => 'users who signed up in the last ' . ($arguments['signed_up_within_days'] ?? '?') . ' days',
            default => 'all customers',
        };

        return "Send \"{$arguments['title']}\" to {$who} via {$channels}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $channels = array_map(
            fn ($c) => self::CHANNEL_MAP[$c],
            $arguments['channels'] ?? ['in_app'],
        );

        $payload = [
            'name' => $arguments['title'],
            'channels' => $channels,
            'sendNow' => true,
            'priorityHigh' => (bool) ($arguments['priority_high'] ?? false),
            'emailCategory' => 'transactional',
            'notifTitle' => $arguments['title'],
            'notifMessage' => $arguments['message'],
            'emailSubject' => $arguments['title'],
            'emailBody' => $arguments['message'],
            'smsMessage' => Str::limit($arguments['message'], 160, ''),
        ];

        if ($arguments['target'] === 'user') {
            $userId = $arguments['user_id']
                ?? User::where('email', $arguments['user_email'] ?? '')->value('id');
            if (!$userId) {
                throw new AiManagerException('Could not find the target customer.');
            }
            $payload['audience_mode'] = 'individuals';
            $payload['user_ids'] = [(int) $userId];
        } else {
            $payload['audience_mode'] = 'criteria';
            if ($arguments['target'] === 'new_users') {
                if (empty($arguments['signed_up_within_days'])) {
                    throw new AiManagerException('signed_up_within_days is required when target is new_users.');
                }
                $payload['signed_up_within_days'] = (int) $arguments['signed_up_within_days'];
            }
        }

        $request = Request::create('/api/admin/broadcast', 'POST', $payload);
        $request->setUserResolver(fn () => $actor);

        $response = app(BroadcastController::class)->send($request);
        $data = $response->getData(true);

        if (($data['success'] ?? false) !== true) {
            throw new AiManagerException($data['message'] ?? 'Broadcast failed to send.');
        }

        return [
            'sent' => true,
            'summary' => $this->summarize($arguments),
            'details' => $data['data'] ?? $data,
        ];
    }
}
