<?php

namespace App\Services\AI;

use App\Contracts\AssistantProvider;
use App\Exceptions\AssistantProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiAssistantProvider implements AssistantProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(private readonly AiProviderConfiguration $configuration) {}

    public function isAvailable(): bool
    {
        $configuration = $this->configuration->current();

        return $configuration['provider'] === 'gemini'
            && is_string($configuration['model'])
            && preg_match('/\A[a-zA-Z0-9._-]{1,120}\z/', trim($configuration['model'])) === 1
            && is_string($configuration['api_key']) && trim($configuration['api_key']) !== '';
    }

    public function isExternal(): bool
    {
        return $this->isAvailable();
    }

    public function label(): string
    {
        return $this->isAvailable() ? 'Gemini' : 'No provider configured';
    }

    public function complete(AssistantCompletionRequest $request): AssistantCompletion
    {
        if (! $this->isAvailable()) {
            throw new AssistantProviderException('The assistant provider is not configured.');
        }

        $configuration = $this->configuration->current();
        [$system, $contents] = $this->contents($request->messages);
        $tools = $this->tools($request->tools);
        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 700],
        ];
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($tools !== []) {
            $payload['tools'] = [['functionDeclarations' => $tools]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['x-goog-api-key' => (string) $configuration['api_key']])
                ->connectTimeout(5)
                ->timeout((int) config('ai.timeout_seconds', 20))
                ->post(self::ENDPOINT.rawurlencode(trim((string) $configuration['model'])).':generateContent', $payload);
        } catch (ConnectionException) {
            throw new AssistantProviderException('The assistant provider could not be reached.');
        }

        if (! $response->successful()) {
            throw new AssistantProviderException('The assistant provider returned an unsuccessful response.');
        }

        $candidate = $response->json('candidates.0.content');
        $parts = data_get($candidate, 'parts');
        if (! is_array($candidate) || ! is_array($parts) || count($parts) > 32) {
            throw new AssistantProviderException('The assistant provider returned an invalid response.');
        }

        $candidateJson = json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (mb_strlen($candidateJson) > 16000) {
            throw new AssistantProviderException('The assistant provider returned an invalid response.');
        }

        $text = [];
        $toolCalls = [];
        foreach ($parts as $part) {
            $partText = data_get($part, 'text');
            if (is_string($partText)) {
                if (mb_strlen($partText) > 8000) {
                    throw new AssistantProviderException('The assistant provider returned an invalid response.');
                }
                $text[] = $partText;
            }

            $call = data_get($part, 'functionCall');
            if ($call !== null) {
                $toolCalls[] = AssistantProviderToolCallFactory::make(
                    data_get($call, 'id'),
                    data_get($call, 'name'),
                    data_get($call, 'args'),
                    allowMissingId: true,
                );
            }
            if (count($toolCalls) > 4) {
                throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
            }
        }

        return new AssistantCompletion(
            mb_substr(trim(implode("\n", $text)), 0, 8000) ?: null,
            $toolCalls,
            $toolCalls === [] ? [] : ['gemini_candidate_content' => $candidate],
        );
    }

    /** @param list<array<string, mixed>> $source
     * @return array{string, list<array{role: string, parts: list<array<string, mixed>>}>}
     */
    private function contents(array $source): array
    {
        $system = [];
        $contents = [];
        $toolNames = [];

        foreach ($source as $message) {
            $role = $message['role'] ?? null;
            if ($role === 'system') {
                if (is_string($message['content'] ?? null)) {
                    $system[] = $message['content'];
                }
                continue;
            }

            if ($role === 'tool') {
                $callId = (string) ($message['tool_call_id'] ?? '');
                $name = $toolNames[$callId] ?? null;
                $result = json_decode((string) ($message['content'] ?? ''), true);
                if (! is_string($name) || ! is_array($result) || ($result !== [] && array_is_list($result))) {
                    throw new AssistantProviderException('The assistant provider received an invalid tool result.');
                }
                $responsePart = ['functionResponse' => ['name' => $name, 'response' => ['result' => (object) $result]]];
                $last = array_key_last($contents);
                if ($last !== null && $contents[$last]['role'] === 'user'
                    && isset($contents[$last]['parts'][0]['functionResponse'])) {
                    $contents[$last]['parts'][] = $responsePart;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$responsePart]];
                }
                continue;
            }

            if (! in_array($role, ['user', 'assistant'], true)) {
                throw new AssistantProviderException('The assistant provider received an invalid conversation.');
            }

            if ($role === 'assistant' && is_array($message['provider_data']['gemini_candidate_content'] ?? null)) {
                $candidate = $message['provider_data']['gemini_candidate_content'];
                if (($candidate['role'] ?? null) !== 'model' || ! is_array($candidate['parts'] ?? null)) {
                    throw new AssistantProviderException('The assistant provider received an invalid conversation.');
                }
                foreach (($message['tool_calls'] ?? []) as $call) {
                    $toolNames[(string) data_get($call, 'id')] = (string) data_get($call, 'function.name');
                }
                $contents[] = $candidate;
                continue;
            }

            $parts = [];
            $text = $message['content'] ?? null;
            if (is_string($text) && $text !== '') {
                $parts[] = ['text' => $text];
            }
            if ($role === 'assistant' && is_array($message['tool_calls'] ?? null)) {
                foreach ($message['tool_calls'] as $call) {
                    $arguments = json_decode((string) data_get($call, 'function.arguments'), true);
                    if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                        throw new AssistantProviderException('The assistant provider received an invalid tool call.');
                    }
                    $id = (string) data_get($call, 'id');
                    $name = (string) data_get($call, 'function.name');
                    $toolNames[$id] = $name;
                    $parts[] = ['functionCall' => ['id' => $id, 'name' => $name, 'args' => (object) $arguments]];
                }
            }
            if ($parts !== []) {
                $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => $parts];
            }
        }

        return [implode("\n", $system), $contents];
    }

    /** @param list<array<string, mixed>> $definitions
     * @return list<array{name: string, description: string, parameters: mixed}>
     */
    private function tools(array $definitions): array
    {
        return array_map(static fn (array $tool): array => [
            'name' => (string) data_get($tool, 'function.name'),
            'description' => (string) data_get($tool, 'function.description'),
            'parameters' => data_get($tool, 'function.parameters'),
        ], $definitions);
    }
}
