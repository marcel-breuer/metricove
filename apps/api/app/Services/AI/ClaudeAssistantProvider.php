<?php

namespace App\Services\AI;

use App\Contracts\AssistantProvider;
use App\Exceptions\AssistantProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ClaudeAssistantProvider implements AssistantProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2026-10-21';

    public function __construct(private readonly AiProviderConfiguration $configuration) {}

    public function isAvailable(): bool
    {
        $configuration = $this->configuration->current();

        return $configuration['provider'] === 'claude'
            && is_string($configuration['model']) && trim($configuration['model']) !== ''
            && is_string($configuration['api_key']) && trim($configuration['api_key']) !== '';
    }

    public function isExternal(): bool
    {
        return $this->isAvailable();
    }

    public function label(): string
    {
        return $this->isAvailable() ? 'Claude' : 'No provider configured';
    }

    public function complete(AssistantCompletionRequest $request): AssistantCompletion
    {
        if (! $this->isAvailable()) {
            throw new AssistantProviderException('The assistant provider is not configured.');
        }

        $configuration = $this->configuration->current();
        [$system, $messages] = $this->messages($request->messages);
        $tools = $this->tools($request->tools);
        $payload = [
            'model' => trim((string) $configuration['model']),
            'max_tokens' => 700,
            'messages' => $messages,
            'temperature' => 0.2,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = ['type' => 'auto'];
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'x-api-key' => (string) $configuration['api_key'],
                    'anthropic-version' => self::API_VERSION,
                ])
                ->connectTimeout(5)
                ->timeout((int) config('ai.timeout_seconds', 20))
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException) {
            throw new AssistantProviderException('The assistant provider could not be reached.');
        }

        if (! $response->successful()) {
            throw new AssistantProviderException('The assistant provider returned an unsuccessful response.');
        }

        $blocks = $response->json('content');
        if (! is_array($blocks) || count($blocks) > 32) {
            throw new AssistantProviderException('The assistant provider returned an invalid response.');
        }

        $text = [];
        $toolCalls = [];
        foreach ($blocks as $block) {
            if (data_get($block, 'type') === 'text') {
                $blockText = data_get($block, 'text');
                if (! is_string($blockText) || mb_strlen($blockText) > 8000) {
                    throw new AssistantProviderException('The assistant provider returned an invalid response.');
                }
                $text[] = $blockText;
            } elseif (data_get($block, 'type') === 'tool_use') {
                $toolCalls[] = AssistantProviderToolCallFactory::make(
                    data_get($block, 'id'),
                    data_get($block, 'name'),
                    data_get($block, 'input'),
                );
            }
            if (count($toolCalls) > 4) {
                throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
            }
        }

        return new AssistantCompletion(mb_substr(trim(implode("\n", $text)), 0, 8000) ?: null, $toolCalls);
    }

    /** @param list<array<string, mixed>> $source
     * @return array{string, list<array{role: string, content: string|list<array<string, mixed>>}>}
     */
    private function messages(array $source): array
    {
        $system = [];
        $messages = [];

        foreach ($source as $message) {
            $role = $message['role'] ?? null;
            if ($role === 'system') {
                if (is_string($message['content'] ?? null)) {
                    $system[] = $message['content'];
                }
                continue;
            }

            if ($role === 'tool') {
                $result = ['type' => 'tool_result', 'tool_use_id' => (string) ($message['tool_call_id'] ?? ''), 'content' => (string) ($message['content'] ?? '')];
                $last = array_key_last($messages);
                if ($last !== null && $messages[$last]['role'] === 'user' && is_array($messages[$last]['content'])) {
                    $messages[$last]['content'][] = $result;
                } else {
                    $messages[] = ['role' => 'user', 'content' => [$result]];
                }
                continue;
            }

            if (! in_array($role, ['user', 'assistant'], true)) {
                throw new AssistantProviderException('The assistant provider received an invalid conversation.');
            }

            $content = is_string($message['content'] ?? null) ? $message['content'] : '';
            $blocks = [];
            if ($content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $content];
            }

            if ($role === 'assistant' && is_array($message['tool_calls'] ?? null)) {
                foreach ($message['tool_calls'] as $call) {
                    $arguments = json_decode((string) data_get($call, 'function.arguments'), false);
                    if (! $arguments instanceof \stdClass) {
                        throw new AssistantProviderException('The assistant provider received an invalid tool call.');
                    }
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => (string) data_get($call, 'id'),
                        'name' => (string) data_get($call, 'function.name'),
                        'input' => $arguments,
                    ];
                }
            }

            $messages[] = ['role' => $role, 'content' => $blocks === [] ? $content : $blocks];
        }

        return [implode("\n", $system), $messages];
    }

    /** @param list<array<string, mixed>> $definitions
     * @return list<array{name: string, description: string, input_schema: mixed}>
     */
    private function tools(array $definitions): array
    {
        return array_map(static fn (array $tool): array => [
            'name' => (string) data_get($tool, 'function.name'),
            'description' => (string) data_get($tool, 'function.description'),
            'input_schema' => data_get($tool, 'function.parameters'),
        ], $definitions);
    }
}
