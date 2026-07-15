<?php

namespace App\Services\AiManager;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around OpenAI's Responses API, scoped to what the AI Manager
 * needs: non-streaming turns that may return text and/or function calls.
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
     * Send one turn to Responses API and return the app's stable AI shape.
     *
     * @param array<int, array> $messages
     * @param array<int, array> $tools
     * @param array<int, array> $functionOutputs
     *
     * @return array{response_id: string|null, content: string|null, tool_calls: array<int, array{call_id: string|null, name: string, arguments: array}>}
     */
    public function chat(
        array $messages,
        array $tools = [],
        array $functionOutputs = [],
        ?string $previousResponseId = null,
    ): array
    {
        $payload = $this->buildPayload($messages, $tools, $functionOutputs, $previousResponseId);

        $baseUrl = rtrim($this->baseUrl ?? config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = $this->timeout ?? (int) config('services.openai.timeout', 60);
        $maxRetries = max(0, (int) config('services.openai.max_retries', 2));

        try {
            $response = Http::withToken($this->key())
                ->timeout($timeout)
                // Retry transient failures (network drops, rate limits, 5xx)
                // with exponential-ish backoff so a blip doesn't fail the turn.
                // Real errors (4xx other than 429) fall straight through.
                ->retry($maxRetries + 1, 600, function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    if ($exception instanceof RequestException) {
                        return in_array($exception->response->status(), [429, 500, 502, 503, 504], true);
                    }

                    return false;
                }, throw: false)
                ->acceptJson()
                ->post("{$baseUrl}/responses", $payload);
        } catch (\Throwable $e) {
            Log::error('AI Manager: OpenAI request failed to send', ['error' => $e->getMessage()]);
            throw new AiManagerException('Could not reach the AI service. Please try again.');
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = $body['error']['message'] ?? 'OpenAI request failed.';
            Log::error('AI Manager: OpenAI returned an error', [
                'status' => $response->status(),
                'type' => $body['error']['type'] ?? null,
                'code' => $body['error']['code'] ?? null,
                'message' => $message,
                'request_id' => $response->header('x-request-id'),
            ]);

            throw new AiManagerException(
                'The AI service returned an error'
                . ($response->status() === 401 ? ' (check OPENAI_API_KEY).' : '. Please try again.')
            );
        }

        $body = $response->json();

        if (!is_array($body)) {
            throw new AiManagerException('The AI service returned an unexpected response.');
        }

        return $this->normalizeResponse($body);
    }

    /**
     * Streaming variant of chat(): opens the Responses API in stream mode and
     * invokes $onDelta($text) for each output-text delta as it arrives, then
     * returns the same normalized shape as chat() from the completed event.
     * Used by the SSE endpoint; the non-streaming chat() path is unchanged.
     *
     * @param array<int, array> $messages
     * @param array<int, array> $tools
     * @param array<int, array> $functionOutputs
     *
     * @return array{response_id: string|null, content: string|null, tool_calls: array<int, array>}
     */
    public function chatStream(
        array $messages,
        array $tools,
        array $functionOutputs,
        ?string $previousResponseId,
        callable $onDelta,
    ): array {
        $payload = $this->buildPayload($messages, $tools, $functionOutputs, $previousResponseId);
        $payload['stream'] = true;

        $baseUrl = rtrim($this->baseUrl ?? config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = $this->timeout ?? (int) config('services.openai.timeout', 60);

        try {
            $response = Http::withToken($this->key())
                ->timeout($timeout)
                ->withOptions(['stream' => true])
                ->post("{$baseUrl}/responses", $payload);
        } catch (\Throwable $e) {
            Log::error('AI Manager: OpenAI stream failed to open', ['error' => $e->getMessage()]);
            throw new AiManagerException('Could not reach the AI service. Please try again.');
        }

        if ($response->failed()) {
            $body = $response->json();
            $this->logApiError(
                'AI Manager: OpenAI stream could not be opened',
                $response->status(),
                is_array($body) ? ($body['error'] ?? []) : [],
                $response->header('x-request-id'),
            );

            throw new AiManagerException($this->publicErrorMessage($response->status()));
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $final = null;
        $streamedContent = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(2048);
            if ($chunk === '') {
                // A PSR-7 stream may report eof only after an additional read.
                // Do not spin forever when the connection has already closed.
                break;
            }
            $buffer .= $chunk;

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $event = json_decode($data, true);
                if (!is_array($event)) {
                    continue;
                }

                $type = $event['type'] ?? '';
                if ($type === 'response.output_text.delta' && isset($event['delta']) && is_string($event['delta'])) {
                    $streamedContent .= $event['delta'];
                    $onDelta($event['delta']);
                } elseif ($type === 'response.completed' && isset($event['response']) && is_array($event['response'])) {
                    // The completed event carries the authoritative response
                    // (id + all output items incl. function calls).
                    $final = $this->normalizeResponse($event['response']);
                } elseif ($type === 'response.incomplete' && isset($event['response']) && is_array($event['response'])) {
                    // An incomplete response can still contain useful text. Keep
                    // it when present; otherwise make the turn eligible for the
                    // non-streaming recovery path in AiManagerService.
                    $incomplete = $this->normalizeResponse($event['response']);
                    if ($incomplete['content'] !== null || !empty($incomplete['tool_calls'])) {
                        $final = $incomplete;
                    } else {
                        $reason = $event['response']['incomplete_details']['reason'] ?? null;
                        $this->logStreamError($type, [
                            'code' => $reason,
                            'message' => 'The response ended before producing output.',
                        ], $event['response']['id'] ?? null);
                        throw new AiManagerException('The AI service could not finish the response. Please try again.');
                    }
                } elseif ($type === 'response.failed') {
                    $error = is_array($event['response']['error'] ?? null)
                        ? $event['response']['error']
                        : [];
                    $this->logStreamError($type, $error, $event['response']['id'] ?? null);
                    throw new AiManagerException('The AI service could not finish the response. Please try again.');
                } elseif ($type === 'error') {
                    $this->logStreamError($type, $event, $event['request_id'] ?? null);
                    throw new AiManagerException('The AI service could not finish the response. Please try again.');
                }
            }
        }

        // Fall back to the text we accumulated if no completed event arrived.
        if ($final === null) {
            $trimmed = trim($streamedContent);
            if ($trimmed === '') {
                Log::warning('AI Manager: OpenAI stream closed without a terminal event');
                throw new AiManagerException('The AI service connection ended unexpectedly. Please try again.');
            }

            $final = [
                'response_id' => $previousResponseId,
                'content' => $trimmed,
                'tool_calls' => [],
            ];
        }

        return $final;
    }

    /**
     * Build the Responses API request body shared by chat() and chatStream().
     *
     * @param array<int, array> $messages
     * @param array<int, array> $tools
     * @param array<int, array> $functionOutputs
     */
    private function buildPayload(
        array $messages,
        array $tools,
        array $functionOutputs,
        ?string $previousResponseId,
    ): array {
        [$instructions, $input] = $this->responsesInputFromMessages($messages);
        if (!empty($functionOutputs)) {
            $input = array_values($functionOutputs);
        }

        $payload = [
            'model' => $this->model(),
            'input' => $input,
            'instructions' => $instructions,
            'max_output_tokens' => $this->maxTokens ?? (int) config('services.openai.max_output_tokens', 1500),
            'reasoning' => [
                'effort' => config('services.openai.reasoning_effort', 'low'),
            ],
        ];

        if ($this->supportsTemperature()) {
            $payload['temperature'] = (float) config('services.openai.temperature', 0.2);
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        if ($previousResponseId !== null) {
            $payload['previous_response_id'] = $previousResponseId;
        }

        return $payload;
    }

    /**
     * A short (3-6 word) title for a conversation, from its first user message.
     * Deliberately tiny (few output tokens, no tools) so the extra call barely
     * costs anything. Returns null on any failure so titling never blocks a turn.
     */
    public function title(string $userMessage): ?string
    {
        $baseUrl = rtrim($this->baseUrl ?? config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        $payload = [
            'model' => $this->model(),
            'instructions' => 'Generate a concise 3 to 6 word title summarising this admin request. Return only the title text — no quotes, no trailing punctuation.',
            'input' => [['role' => 'user', 'content' => mb_substr($userMessage, 0, 500)]],
            'max_output_tokens' => 20,
            'reasoning' => ['effort' => 'minimal'],
        ];

        try {
            $response = Http::withToken($this->key())
                ->timeout(20)
                ->acceptJson()
                ->post("{$baseUrl}/responses", $payload);

            if ($response->failed()) {
                return null;
            }

            $title = trim((string) ($this->normalizeResponse($response->json())['content'] ?? ''));
            $title = trim($title, " \t\n\r\0\x0B\"'");

            return $title !== '' ? mb_substr($title, 0, 60) : null;
        } catch (\Throwable $e) {
            Log::info('AI Manager: title generation skipped', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function supportsTemperature(): bool
    {
        return !preg_match('/^(o\d|gpt-5)/i', $this->model());
    }

    /**
     * Record provider details for operators without exposing them in the UI.
     *
     * @param array<string, mixed> $error
     */
    private function logStreamError(string $eventType, array $error, ?string $responseId): void
    {
        Log::error('AI Manager: OpenAI stream returned an error', [
            'event_type' => $eventType,
            'type' => $error['type'] ?? null,
            'code' => $error['code'] ?? null,
            'message' => $error['message'] ?? 'Unknown streaming error.',
            'param' => $error['param'] ?? null,
            'response_id' => $responseId,
        ]);
    }

    /**
     * @param array<string, mixed> $error
     */
    private function logApiError(string $logMessage, int $status, array $error, ?string $requestId): void
    {
        Log::error($logMessage, [
            'status' => $status,
            'type' => $error['type'] ?? null,
            'code' => $error['code'] ?? null,
            'message' => $error['message'] ?? 'OpenAI request failed.',
            'request_id' => $requestId,
        ]);
    }

    private function publicErrorMessage(int $status): string
    {
        return 'The AI service returned an error'
            . ($status === 401 ? ' (check OPENAI_API_KEY).' : '. Please try again.');
    }

    /**
     * @param array<int, array> $messages
     *
     * @return array{0: string|null, 1: array<int, array>}
     */
    private function responsesInputFromMessages(array $messages): array
    {
        $instructions = null;
        $input = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system' && $instructions === null) {
                $instructions = $message['content'] ?? null;
                continue;
            }

            if (isset($message['type'])) {
                $input[] = $message;
                continue;
            }

            if (isset($message[0]) && is_array($message[0])) {
                foreach ($message as $item) {
                    $input[] = $item;
                }
                continue;
            }

            $role = $message['role'] ?? null;
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $input[] = [
                'role' => $role,
                'content' => $message['content'] ?? '',
            ];
        }

        return [$instructions, $input];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{response_id: string|null, content: string|null, tool_calls: array<int, array{call_id: string|null, name: string, arguments: array}>}
     */
    private function normalizeResponse(array $body): array
    {
        $contentParts = [];
        $toolCalls = [];

        foreach (($body['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'message') {
                foreach (($item['content'] ?? []) as $part) {
                    if (!is_array($part)) {
                        continue;
                    }

                    if (isset($part['text']) && is_string($part['text'])) {
                        $contentParts[] = $part['text'];
                    }
                }
            }

            if (($item['type'] ?? null) === 'function_call') {
                $arguments = json_decode($item['arguments'] ?? '{}', true);
                $toolCalls[] = [
                    'call_id' => $item['call_id'] ?? null,
                    'name' => (string) ($item['name'] ?? ''),
                    'arguments' => is_array($arguments) ? $arguments : [],
                ];
            }
        }

        $content = trim(implode("\n", $contentParts));

        return [
            'response_id' => $body['id'] ?? null,
            'content' => $content !== '' ? $content : null,
            'tool_calls' => $toolCalls,
        ];
    }
}
