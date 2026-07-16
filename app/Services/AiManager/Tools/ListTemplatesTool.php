<?php

namespace App\Services\AiManager\Tools;

use App\Models\Template;
use App\Models\User;
use App\Support\TemplateVariables;

/**
 * Read-only view of the notification templates, so the assistant can find the
 * right template id and see the {{variables}} a body already uses before
 * proposing an edit with manage_template.
 */
class ListTemplatesTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'list_templates';
    }

    public function description(): string
    {
        return 'List notification templates (the messages sent to customers on events like login, register, purchase, wallet_credit, wallet_debit, plus broadcast templates). Returns id, name, slug, type, event, subject, content, delivery channels, enabled flag, and the {{variables}} the template uses. Call this before proposing any template change with manage_template so you use real ids and keep existing variables intact.';
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
                'id' => ['type' => 'integer', 'description' => 'Fetch one template by id.'],
                'type' => ['type' => 'string', 'enum' => ['event', 'broadcast'], 'description' => 'Filter by template type.'],
                'event' => [
                    'type' => 'string',
                    'enum' => Template::EVENTS,
                    'description' => 'Filter event templates by the event that triggers them.',
                ],
                'channel' => [
                    'type' => 'string',
                    'enum' => ['email', 'sms', 'in_app', 'push'],
                    'description' => 'Only templates configured to deliver on this channel.',
                ],
                'enabled_only' => ['type' => 'boolean', 'description' => 'Only enabled templates. Default false (all).'],
                'query' => ['type' => 'string', 'description' => 'Match against name, subject, or body content.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50, default 25).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            'type' => 'nullable|in:event,broadcast',
            'event' => 'nullable|in:' . implode(',', Template::EVENTS),
            'channel' => 'nullable|in:email,sms,in_app,push',
            'enabled_only' => 'nullable|boolean',
            'query' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = Template::query();

        if (!empty($arguments['id'])) {
            $query->where('id', $arguments['id']);
        }
        if (!empty($arguments['type'])) {
            $query->where('type', $arguments['type']);
        }
        if (!empty($arguments['event'])) {
            $query->where('event', $arguments['event']);
        }
        if (!empty($arguments['enabled_only'])) {
            $query->where('enabled', true);
        }
        if (!empty($arguments['query'])) {
            $term = $arguments['query'];
            $query->where(function ($inner) use ($term) {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('subject', 'like', "%{$term}%")
                    ->orWhere('content', 'like', "%{$term}%");
            });
        }

        $total = (clone $query)->count();
        $limit = min((int) ($arguments['limit'] ?? 25), self::MAX_LIMIT);

        $rows = $query->orderBy('type')->orderBy('id')->limit($limit)->get();

        // channels is a JSON column; filter in PHP so the tool works the same
        // on any driver rather than depending on JSON_CONTAINS.
        if (!empty($arguments['channel'])) {
            $rows = $rows->filter(fn (Template $t) => $t->hasChannel($arguments['channel']))->values();
        }

        return [
            'total_matches' => $total,
            'returned' => $rows->count(),
            'events_available' => Template::EVENTS,
            'channels_available' => ['email', 'sms', 'in_app', 'push'],
            'variables_note' => 'Variables are written as {{name}} in subject/content and substituted at send time. Only variables in the catalog below resolve — anything else is delivered literally as "{{name}}". Use available_variables to pick valid ones; prefix a placeholder with "custom_" to declare a bespoke one on purpose.',
            // The supported placeholders, so the model proposes edits using real
            // variables instead of inventing ones that would render literally.
            'available_variables' => TemplateVariables::catalog(),
            'templates' => $rows->map(function (Template $t) {
                $unknown = TemplateVariables::unknownIn(
                    ($t->subject ?? '') . "\n" . ($t->content ?? ''),
                    $t->event,
                );

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'type' => $t->type,
                    'event' => $t->event,
                    'subject' => $t->subject,
                    'content' => $t->content,
                    'channels' => $t->channels ?? [],
                    'enabled' => (bool) $t->enabled,
                    'variables' => $t->variables,
                    // Placeholders this template uses that nothing supplies —
                    // they currently render literally and likely need fixing.
                    'unknown_variables' => $unknown,
                ];
            })->all(),
        ];
    }
}
