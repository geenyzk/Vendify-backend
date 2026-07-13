<?php

namespace Tests\Feature;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use App\Services\AiManager\AiManagerService;
use App\Services\AiManager\OpenAiClient;
use App\Services\AiManager\Tools\AiTool;
use App\Services\AiManager\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AiManagerResponsesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai.key', 'test-key');
        config()->set('services.openai.model', 'gpt-test');
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        config()->set('services.openai.reasoning_effort', 'low');
        config()->set('services.openai.max_tool_iterations', 3);

        TestReadTool::$handled = [];
        TestMutatingTool::$handled = [];
    }

    public function test_openai_client_sends_responses_payload_and_normalizes_plain_text(): void
    {
        Http::fake([
            'api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_text',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'All good.']],
                ]],
            ]),
        ]);

        $client = new OpenAiClient(timeout: 5);
        $reply = $client->chat([
            ['role' => 'system', 'content' => 'System prompt'],
            ['role' => 'user', 'content' => 'Hello'],
        ], [[
            'type' => 'function',
            'name' => 'test_read',
            'description' => 'Read',
            'parameters' => ['type' => 'object', 'properties' => (object) []],
            'strict' => true,
        ]]);

        $this->assertSame([
            'response_id' => 'resp_text',
            'content' => 'All good.',
            'tool_calls' => [],
        ], $reply);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.openai.test/v1/responses'
                && $data['model'] === 'gpt-test'
                && $data['instructions'] === 'System prompt'
                && $data['reasoning']['effort'] === 'low'
                && !array_key_exists('reasoning_effort', $data)
                && $data['tools'][0]['name'] === 'test_read'
                && $data['tools'][0]['strict'] === true
                && $data['input'][0]['role'] === 'user';
        });
    }

    public function test_reasoning_models_do_not_send_temperature(): void
    {
        config()->set('services.openai.model', 'gpt-5.6-luna');

        Http::fake([
            'api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_text',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'All good.']],
                ]],
            ]),
        ]);

        (new OpenAiClient())->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        Http::assertSent(fn ($request) => !array_key_exists('temperature', $request->data()));
    }

    public function test_tool_schema_is_converted_to_strict_nullable_optionals(): void
    {
        $schema = (new TestReadTool())->toOpenAiSchema();

        $this->assertTrue($schema['strict']);
        $this->assertSame(['value', 'limit'], $schema['parameters']['required']);
        $this->assertFalse($schema['parameters']['additionalProperties']);
        $this->assertSame(['integer', 'null'], $schema['parameters']['properties']['limit']['type']);
    }

    public function test_openai_client_normalizes_function_calls(): void
    {
        Http::fake([
            'api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_tool',
                'output' => [[
                    'type' => 'function_call',
                    'call_id' => 'call_1',
                    'name' => 'test_read',
                    'arguments' => '{"value":"abc"}',
                ]],
            ]),
        ]);

        $reply = (new OpenAiClient())->chat([
            ['role' => 'user', 'content' => 'Fetch'],
        ]);

        $this->assertSame('resp_tool', $reply['response_id']);
        $this->assertSame([[
            'call_id' => 'call_1',
            'name' => 'test_read',
            'arguments' => ['value' => 'abc'],
        ]], $reply['tool_calls']);
    }

    public function test_openai_client_sends_continuation_with_previous_response_id_and_outputs(): void
    {
        Http::fake([
            'api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_final',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Done']],
                ]],
            ]),
        ]);

        (new OpenAiClient())->chat(
            [['role' => 'user', 'content' => 'Continue']],
            [],
            [[
                'type' => 'function_call_output',
                'call_id' => 'call_1',
                'output' => '{"ok":true}',
            ]],
            'resp_prev',
        );

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['previous_response_id'] === 'resp_prev'
                && $data['input'][0]['type'] === 'function_call_output'
                && $data['input'][0]['call_id'] === 'call_1';
        });
    }

    public function test_openai_400_response_is_logged_and_sanitized(): void
    {
        Log::spy();
        Http::fake([
            'api.openai.test/v1/responses' => Http::response([
                'error' => [
                    'message' => 'Bad request with provider details',
                    'type' => 'invalid_request_error',
                    'code' => 'bad_payload',
                ],
            ], 400),
        ]);

        $this->expectException(AiManagerException::class);
        $this->expectExceptionMessage('The AI service returned an error. Please try again.');

        try {
            (new OpenAiClient())->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) =>
                $message === 'AI Manager: OpenAI returned an error'
                && $context['status'] === 400
                && $context['message'] === 'Bad request with provider details'
            );
        }
    }

    public function test_openai_429_response_is_sanitized(): void
    {
        Http::fake([
            'api.openai.test/v1/responses' => Http::response([
                'error' => ['message' => 'Rate limited'],
            ], 429),
        ]);

        $this->expectException(AiManagerException::class);
        $this->expectExceptionMessage('The AI service returned an error. Please try again.');

        (new OpenAiClient())->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    public function test_openai_timeout_is_sanitized(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout connecting to OpenAI'));

        $this->expectException(AiManagerException::class);
        $this->expectExceptionMessage('Could not reach the AI service. Please try again.');

        (new OpenAiClient())->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    public function test_plain_text_service_response_is_saved(): void
    {
        $service = $this->serviceWithClient([
            ['response_id' => 'resp_1', 'content' => 'Plain answer', 'tool_calls' => []],
        ]);

        $result = $service->sendMessage($this->conversation(), $this->admin(), 'Hi');

        $this->assertSame('Plain answer', $result['assistant']->content);
        $this->assertSame([], $result['proposals']);
    }

    public function test_one_read_only_function_call_is_executed_and_returned_to_openai(): void
    {
        $client = new FakeOpenAiClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_read',
                'name' => 'test_read',
                'arguments' => ['value' => 'one'],
            ]]],
            ['response_id' => 'resp_2', 'content' => 'Finished', 'tool_calls' => []],
        ]);

        $service = $this->serviceWithClient($client);
        $result = $service->sendMessage($this->conversation(), $this->admin(), 'Check');

        $this->assertSame('Finished', $result['assistant']->content);
        $this->assertSame([['value' => 'one']], TestReadTool::$handled);
        $this->assertSame('resp_1', $client->calls[1]['previous_response_id']);
        $this->assertSame('function_call_output', $client->calls[1]['function_outputs'][0]['type']);
        $this->assertSame('call_read', $client->calls[1]['function_outputs'][0]['call_id']);
    }

    public function test_multiple_read_only_calls_are_returned_in_one_continuation(): void
    {
        $client = new FakeOpenAiClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [
                ['call_id' => 'call_a', 'name' => 'test_read', 'arguments' => ['value' => 'a']],
                ['call_id' => 'call_b', 'name' => 'test_read', 'arguments' => ['value' => 'b']],
            ]],
            ['response_id' => 'resp_2', 'content' => 'Done', 'tool_calls' => []],
        ]);

        $this->serviceWithClient($client)->sendMessage($this->conversation(), $this->admin(), 'Check both');

        $this->assertCount(2, $client->calls[1]['function_outputs']);
        $this->assertSame(['call_a', 'call_b'], array_column($client->calls[1]['function_outputs'], 'call_id'));
    }

    public function test_mutating_tool_creates_pending_proposal_without_executing(): void
    {
        $service = $this->serviceWithClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_mutate',
                'name' => 'test_mutate',
                'arguments' => ['value' => 'change'],
            ]]],
            ['response_id' => 'resp_2', 'content' => 'Proposal created', 'tool_calls' => []],
        ]);

        $result = $service->sendMessage($this->conversation(), $this->admin(), 'Change it');

        $this->assertCount(1, $result['proposals']);
        $this->assertSame(AiActionProposal::STATUS_PENDING, $result['proposals'][0]->status);
        $this->assertSame([], TestMutatingTool::$handled);
    }

    public function test_approved_proposal_executes(): void
    {
        $service = $this->serviceWithClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_mutate',
                'name' => 'test_mutate',
                'arguments' => ['value' => 'approve-me'],
            ]]],
            ['response_id' => 'resp_2', 'content' => 'Proposal created', 'tool_calls' => []],
        ]);

        $proposal = $service->sendMessage($this->conversation(), $this->admin(), 'Change it')['proposals'][0];
        $approved = $service->approve($proposal, $this->admin(), false);

        $this->assertSame(AiActionProposal::STATUS_EXECUTED, $approved->status);
        $this->assertSame([['value' => 'approve-me']], TestMutatingTool::$handled);
    }

    public function test_rejected_proposal_does_not_execute(): void
    {
        $service = $this->serviceWithClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_mutate',
                'name' => 'test_mutate',
                'arguments' => ['value' => 'reject-me'],
            ]]],
            ['response_id' => 'resp_2', 'content' => 'Proposal created', 'tool_calls' => []],
        ]);

        $proposal = $service->sendMessage($this->conversation(), $this->admin(), 'Change it')['proposals'][0];
        $rejected = $service->reject($proposal, $this->admin());

        $this->assertSame(AiActionProposal::STATUS_REJECTED, $rejected->status);
        $this->assertSame([], TestMutatingTool::$handled);
    }

    public function test_invalid_function_arguments_are_returned_as_tool_output(): void
    {
        $client = new FakeOpenAiClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_invalid',
                'name' => 'test_read',
                'arguments' => [],
            ]]],
            ['response_id' => 'resp_2', 'content' => 'Handled invalid args', 'tool_calls' => []],
        ]);

        $this->serviceWithClient($client)->sendMessage($this->conversation(), $this->admin(), 'Bad args');

        $output = json_decode($client->calls[1]['function_outputs'][0]['output'], true);
        $this->assertSame('The arguments were invalid.', $output['error']);
    }

    public function test_unknown_function_is_returned_as_tool_output(): void
    {
        $client = new FakeOpenAiClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_unknown',
                'name' => 'missing_tool',
                'arguments' => [],
            ]]],
            ['response_id' => 'resp_2', 'content' => 'Handled unknown tool', 'tool_calls' => []],
        ]);

        $this->serviceWithClient($client)->sendMessage($this->conversation(), $this->admin(), 'Unknown');

        $output = json_decode($client->calls[1]['function_outputs'][0]['output'], true);
        $this->assertSame("The tool 'missing_tool' is not available to you.", $output['error']);
    }

    public function test_max_tool_iteration_limit_returns_fallback(): void
    {
        config()->set('services.openai.max_tool_iterations', 2);
        $client = new FakeOpenAiClient([
            ['response_id' => 'resp_1', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_1',
                'name' => 'test_read',
                'arguments' => ['value' => 'a'],
            ]]],
            ['response_id' => 'resp_2', 'content' => null, 'tool_calls' => [[
                'call_id' => 'call_2',
                'name' => 'test_read',
                'arguments' => ['value' => 'b'],
            ]]],
        ]);

        $result = $this->serviceWithClient($client)->sendMessage($this->conversation(), $this->admin(), 'Loop');

        $this->assertCount(2, $client->calls);
        $this->assertStringContainsString('allowed number of steps', $result['assistant']->content);
    }

    private function serviceWithClient(array|FakeOpenAiClient $responsesOrClient): AiManagerService
    {
        $client = $responsesOrClient instanceof FakeOpenAiClient
            ? $responsesOrClient
            : new FakeOpenAiClient($responsesOrClient);

        $registry = new TestToolRegistry();
        $registry->register(new TestReadTool());
        $registry->register(new TestMutatingTool());

        return new AiManagerService($client, $registry);
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'admin@example.com'], [
            'username' => 'admin',
            'fullname' => 'Admin User',
            'phone' => '08000000000',
            'password' => 'password',
            'user_type' => 'admin',
            'status' => 'active',
            'is_active' => true,
            'is_verified' => true,
        ]);
    }

    private function conversation(): AiConversation
    {
        return AiConversation::create([
            'user_id' => $this->admin()->id,
            'uuid' => 'conversation-' . uniqid(),
            'last_activity_at' => now(),
        ]);
    }
}

class FakeOpenAiClient extends OpenAiClient
{
    /** @var array<int, array> */
    public array $calls = [];

    /**
     * @param array<int, array> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function chat(
        array $messages,
        array $tools = [],
        array $functionOutputs = [],
        ?string $previousResponseId = null,
    ): array {
        $this->calls[] = [
            'messages' => $messages,
            'tools' => $tools,
            'function_outputs' => $functionOutputs,
            'previous_response_id' => $previousResponseId,
        ];

        return array_shift($this->responses) ?? [
            'response_id' => 'empty',
            'content' => 'No queued fake response.',
            'tool_calls' => [],
        ];
    }
}

class TestToolRegistry extends ToolRegistry
{
    public function __construct()
    {
    }
}

class TestReadTool extends AiTool
{
    /** @var array<int, array> */
    public static array $handled = [];

    public function name(): string
    {
        return 'test_read';
    }

    public function description(): string
    {
        return 'Test read tool.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['value' => 'required|string'];
    }

    public function handle(array $arguments, User $actor): array
    {
        self::$handled[] = $arguments;

        return ['read' => true, 'value' => $arguments['value']];
    }
}

class TestMutatingTool extends TestReadTool
{
    /** @var array<int, array> */
    public static array $handled = [];

    public function name(): string
    {
        return 'test_mutate';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Mutate ' . $arguments['value'];
    }

    public function handle(array $arguments, User $actor): array
    {
        self::$handled[] = $arguments;

        return ['mutated' => true, 'value' => $arguments['value']];
    }
}
