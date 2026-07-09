<?php

namespace App\Services\AiManager;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around OpenAI's Chat Completions API, scoped to what the AI
 * Manager needs: one non-streaming request that may return either an assistant
 * message or a batch of tool calls. Matches the Http-facade style used by the
 * vendor providers (see App\Classes\Vendor\Providers\*).
 */
class OpenAiClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $model = null,
        private readonly ?int $maxTokens = null,
        private readonly ?int $timeout = null,
    ) {
    }

    private function key(): string
    {
        $key = $this->apiKey ?? config('services.openai.key');

        if (empty($key)) {
            throw new AiManagerException(
                'The AI Manager is not configured. Set OPENAI_API_KEY in the environment to enable it.'
            );
        }

        return $key;
    }

    public function model(): string
    {
        return $this->model ?? config('services.openai.model', 'gpt-4o');
    }

    /**
     * Send one chat turn. `$messages` is the full replayed transcript in OpenAI
     * shape; `$tools` is the JSON-schema tool list (empty disables tools).
     * Returns the raw `message` object from the first choice, e.g.
     *   ['role' => 'assistant', 'content' => '...', 'tool_calls' => [...]]
     */
    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => $this->model(),
            'messages' => $messages,
            'max_tokens' => $this->maxTokens ?? (int) config('services.openai.max_output_tokens', 1500),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $baseUrl = rtrim($this->baseUrl ?? config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = $this->timeout ?? (int) config('services.openai.timeout', 60);

        try {
            $response = Http::withToken($this->key())
                ->timeout($timeout)
                ->acceptJson()
                ->post("{$baseUrl}/chat/completions", $payload);
        } catch (\Throwable $e) {
            Log::error('AI Manager: OpenAI request failed to send', ['error' => $e->getMessage()]);
            throw new AiManagerException('Could not reach the AI service. Please try again.');
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = $body['error']['message'] ?? $response->body();
            Log::error('AI Manager: OpenAI returned an error', [
                'status' => $response->status(),
                'error' => $message,
            ]);

            throw new AiManagerException(
                'The AI service returned an error'
                . ($response->status() === 401 ? ' (check OPENAI_API_KEY).' : ': ' . $message)
            );
        }

        $message = $response->json('choices.0.message');

        if (!is_array($message)) {
            throw new AiManagerException('The AI service returned an unexpected response.');
        }

        return $message;
    }
}
