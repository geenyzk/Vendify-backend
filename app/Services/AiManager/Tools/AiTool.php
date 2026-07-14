<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Base class for every capability the AI Manager can invoke.
 *
 * Two flavours:
 *  - Read-only tools (isMutating() === false) run immediately and feed their
 *    result back to the model, grounding its answers in live site data.
 *  - Mutating tools (isMutating() === true) are NEVER executed inline. The
 *    orchestrator turns a call into an AiActionProposal that an admin must
 *    approve; only then does handle() run. permission() names the real admin
 *    permission slug the actor must hold — enforced both when exposing the
 *    tool to the model and again at approval time (defence in depth).
 */
abstract class AiTool
{
    /** Snake_case identifier the model calls, e.g. "search_transactions". */
    abstract public function name(): string;

    /** Plain-language description the model uses to decide when to call it. */
    abstract public function description(): string;

    /**
     * JSON Schema for the arguments object (the `parameters` of the function).
     * Return a full object schema, e.g.
     *   ['type' => 'object', 'properties' => [...], 'required' => [...]].
     */
    abstract public function parameters(): array;

    /**
     * Do the work. For read tools this runs inline; for mutating tools this
     * runs only after an admin approves the proposal. Return a JSON-serialisable
     * array (it is shown to the model and, for actions, stored as the result).
     */
    abstract public function handle(array $arguments, User $actor): array;

    /** Laravel validation rules applied to arguments before handle() runs. */
    public function rules(): array
    {
        return [];
    }

    public function isMutating(): bool
    {
        return false;
    }

    /**
     * Permission slug the actor must hold to use this tool. Null means it is
     * available to any admin who can reach the AI Manager (the route group is
     * already gated by permission:ai_manager).
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * One-line, human-readable description of what approving this action will
     * do, shown on the approval card. Override for mutating tools.
     */
    public function summarize(array $arguments): string
    {
        return $this->name();
    }

    /**
     * Validate raw arguments coming from the model against rules(). Throws a
     * ValidationException the orchestrator turns into a tool error the model
     * can read and retry.
     */
    public function validate(array $arguments): array
    {
        if (empty($this->rules())) {
            return $arguments;
        }

        return Validator::make($arguments, $this->rules())->validate();
    }

    /** The `tools` entry OpenAI Responses API expects for this capability. */
    public function toOpenAiSchema(): array
    {
        return [
            'type' => 'function',
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => $this->strictParameters($this->parameters()),
            'strict' => true,
        ];
    }

    private function strictParameters(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object') {
            $originalRequired = $schema['required'] ?? [];
            $properties = $this->schemaProperties($schema['properties'] ?? []);
            $propertyNames = array_keys($properties);

            $schema['required'] = $propertyNames;
            $schema['additionalProperties'] = false;

            foreach ($properties as $name => $property) {
                if (!is_array($property)) {
                    continue;
                }

                $property = $this->strictParameters($property);

                if (!in_array($name, $originalRequired, true)) {
                    $property = $this->nullableSchema($property);
                }

                $schema['properties'][$name] = $property;
            }

            if ($propertyNames === []) {
                $schema['properties'] = (object) [];
            }
        }

        // Array item schemas need the same strict treatment (every nested
        // object must list all its keys in `required`), otherwise the OpenAI
        // strict validator rejects the whole function schema.
        if (($schema['type'] ?? null) === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->strictParameters($schema['items']);
        }

        return $schema;
    }

    /**
     * Tool schemas use `(object) []` for empty JSON objects in a few places.
     *
     * @return array<string, mixed>
     */
    private function schemaProperties(mixed $properties): array
    {
        if ($properties instanceof \stdClass) {
            return get_object_vars($properties);
        }

        return is_array($properties) ? $properties : [];
    }

    private function nullableSchema(array $schema): array
    {
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            if (!in_array('null', $types, true)) {
                $types[] = 'null';
            }
            $schema['type'] = $types;

            return $schema;
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $schema['anyOf'][] = ['type' => 'null'];

            return $schema;
        }

        $schema['anyOf'] = [
            $schema,
            ['type' => 'null'],
        ];

        return $schema;
    }
}
