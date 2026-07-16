<?php

namespace App\Services\AiManager\Tools;

use App\Models\Template;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use App\Support\TemplateVariables;

/**
 * Create, update, or delete a notification template. Mutating: proposal-only
 * and gated by `settings`, since these bodies go out to real customers.
 *
 * Mirrors ManagePlanTool: one tool with an `action` discriminator rather than
 * three near-identical tools, so the model has a single obvious way to change a
 * template instead of picking between overlapping options.
 */
class ManageTemplateTool extends AiTool
{
    private const CHANNELS = ['email', 'sms', 'in_app', 'push'];

    public function name(): string
    {
        return 'manage_template';
    }

    public function description(): string
    {
        return 'Propose creating, updating, or deleting a notification template (the message customers receive on events like login, register, purchase, wallet_credit, wallet_debit, or a broadcast template). Call list_templates first to get the real id, the existing {{variables}}, and the available_variables catalog. Use only catalog variables in the body — anything else renders literally to the customer as "{{name}}" (prefix with "custom_" to declare a bespoke one on purpose). The result reports unknown_variables so you can warn the admin. Only "content" is required to create; the slug is generated automatically. Creates a pending action an admin must approve.';
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
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'update', 'delete'],
                    'description' => 'What to do with the template.',
                ],
                'id' => ['type' => 'integer', 'description' => 'Template id. Required for update and delete.'],
                'name' => ['type' => 'string', 'description' => 'Human-friendly name shown in the admin UI. Required on create.'],
                'type' => [
                    'type' => 'string',
                    'enum' => ['event', 'broadcast'],
                    'description' => 'event = sent automatically when its event fires; broadcast = sent manually. Default event.',
                ],
                'event' => [
                    'type' => 'string',
                    'enum' => Template::EVENTS,
                    'description' => 'Which event triggers this template. Only meaningful when type is "event".',
                ],
                'subject' => ['type' => 'string', 'description' => 'Email subject / notification headline.'],
                'content' => ['type' => 'string', 'description' => 'Body text. Use {{variable}} placeholders, e.g. {{name}} or {{amount}}.'],
                'channels' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::CHANNELS],
                    'description' => 'Delivery channels: email, sms, in_app, push.',
                ],
                'enabled' => ['type' => 'boolean', 'description' => 'Whether the template is live.'],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:create,update,delete',
            'id' => 'nullable|integer',
            'name' => 'nullable|string|max:191',
            'type' => 'nullable|in:event,broadcast',
            'event' => 'nullable|in:' . implode(',', Template::EVENTS),
            'subject' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'channels' => 'nullable|array',
            'channels.*' => 'in:' . implode(',', self::CHANNELS),
            'enabled' => 'nullable|boolean',
        ];
    }

    public function summarize(array $arguments): string
    {
        $action = $arguments['action'] ?? 'change';
        $label = $arguments['name'] ?? (isset($arguments['id']) ? "template #{$arguments['id']}" : 'template');

        return match ($action) {
            'create' => "Create notification template \"{$label}\"" . $this->channelSuffix($arguments),
            'update' => "Update notification template {$label}" . $this->changedFieldsSuffix($arguments),
            'delete' => "Delete notification template {$label}",
            default => "Change notification template {$label}",
        };
    }

    public function handle(array $arguments, User $actor): array
    {
        return match ($arguments['action']) {
            'create' => $this->create($arguments),
            'update' => $this->update($arguments),
            'delete' => $this->delete($arguments),
            default => throw new AiManagerException("Unknown action '{$arguments['action']}'."),
        };
    }

    private function create(array $arguments): array
    {
        if (empty($arguments['name'])) {
            throw new AiManagerException('A name is required to create a template.');
        }
        if (empty($arguments['content'])) {
            throw new AiManagerException('Content is required to create a template.');
        }

        $type = $arguments['type'] ?? 'event';

        // An event template with no event never fires — that is a silently
        // broken template, so reject it rather than create dead config.
        if ($type === 'event' && empty($arguments['event'])) {
            throw new AiManagerException(
                'An "event" template must specify which event triggers it (' . implode(', ', Template::EVENTS) . '), otherwise it would never be sent.'
            );
        }

        $template = Template::create([
            'name' => $arguments['name'],
            'type' => $type,
            // A broadcast template has no triggering event.
            'event' => $type === 'event' ? ($arguments['event'] ?? null) : null,
            'subject' => $arguments['subject'] ?? null,
            'content' => $arguments['content'],
            'channels' => $arguments['channels'] ?? ['in_app'],
            'enabled' => $arguments['enabled'] ?? true,
        ]);

        return [
            'created' => true,
            'template' => $this->payload($template->fresh()),
        ];
    }

    private function update(array $arguments): array
    {
        $template = $this->find($arguments);
        $before = $this->payload($template);

        $fields = array_intersect_key($arguments, array_flip(
            ['name', 'type', 'event', 'subject', 'content', 'channels', 'enabled'],
        ));

        if ($fields === []) {
            throw new AiManagerException('Nothing to update — provide at least one field to change.');
        }

        // Switching to broadcast clears the trigger; switching to event without
        // one would leave a template that can never fire.
        $newType = $fields['type'] ?? $template->type;
        if ($newType === 'broadcast') {
            $fields['event'] = null;
        } elseif (empty($fields['event']) && empty($template->event)) {
            throw new AiManagerException(
                'An "event" template must specify which event triggers it (' . implode(', ', Template::EVENTS) . ').'
            );
        }

        $template->fill($fields)->save();

        return [
            'updated' => true,
            'before' => $before,
            'template' => $this->payload($template->fresh()),
        ];
    }

    private function delete(array $arguments): array
    {
        $template = $this->find($arguments);
        $payload = $this->payload($template);
        $template->delete(); // Soft delete — recoverable.

        return [
            'deleted' => true,
            'note' => 'Soft-deleted; the row is recoverable in the database.',
            'template' => $payload,
        ];
    }

    private function find(array $arguments): Template
    {
        if (empty($arguments['id'])) {
            throw new AiManagerException("An id is required to {$arguments['action']} a template. Use list_templates to find it.");
        }

        $template = Template::find($arguments['id']);
        if (!$template) {
            throw new AiManagerException("No template found with id {$arguments['id']}.");
        }

        return $template;
    }

    private function payload(Template $template): array
    {
        $unknown = TemplateVariables::unknownIn(
            ($template->subject ?? '') . "\n" . ($template->content ?? ''),
            $template->event,
        );

        return [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'type' => $template->type,
            'event' => $template->event,
            'subject' => $template->subject,
            'content' => $template->content,
            'channels' => $template->channels ?? [],
            'enabled' => (bool) $template->enabled,
            'variables' => $template->variables,
            // Placeholders nothing supplies for this event — they will render
            // literally. Surfaced (not blocked) so the model can warn the admin
            // or switch to a supported variable / a custom_ prefix.
            'unknown_variables' => $unknown,
        ];
    }

    private function channelSuffix(array $arguments): string
    {
        $channels = $arguments['channels'] ?? null;

        return $channels ? ' via ' . implode(', ', $channels) : '';
    }

    /** Name the fields an update touches, so the approval card is specific. */
    private function changedFieldsSuffix(array $arguments): string
    {
        $fields = array_keys(array_intersect_key($arguments, array_flip(
            ['name', 'type', 'event', 'subject', 'content', 'channels', 'enabled'],
        )));

        return $fields ? ' (' . implode(', ', $fields) . ')' : '';
    }
}
