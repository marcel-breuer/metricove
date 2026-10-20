<?php

namespace App\Services\AI;

use App\Contracts\AssistantProvider;
use App\Exceptions\AssistantProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiCompatibleAssistantProvider implements AssistantProvider
{
    public function isAvailable(): bool
    {
        return config('ai.provider') === 'openai-compatible'
            && is_string(config('ai.base_url'))
            && $this->validBaseUrl(config('ai.base_url'))
            && is_string(config('ai.model'))
            && trim(config('ai.model')) !== '';
    }

    public function isExternal(): bool
    {
        return $this->isAvailable() && $this->externalProviderConfigured();
    }

    public function label(): string
    {
        if (! $this->isAvailable()) {
            return 'No provider configured';
        }

        return $this->isExternal()
            ? 'External OpenAI-compatible provider'
            : 'Local OpenAI-compatible provider';
    }

    public function complete(AssistantCompletionRequest $request): AssistantCompletion
    {
        if (! $this->isAvailable()) {
            throw new AssistantProviderException('The assistant provider is not configured.');
        }

        $client = Http::acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('ai.timeout_seconds', 20));
        $apiKey = config('ai.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            $client = $client->withToken($apiKey);
        }

        try {
            $response = $client->post(rtrim((string) config('ai.base_url'), '/').'/chat/completions', [
                'model' => trim((string) config('ai.model')),
                'messages' => $request->messages,
                'tools' => $request->tools,
                'tool_choice' => 'auto',
                'temperature' => 0.2,
                'max_tokens' => 700,
            ]);
        } catch (ConnectionException) {
            throw new AssistantProviderException('The assistant provider could not be reached.');
        }

        if (! $response->successful()) {
            throw new AssistantProviderException('The assistant provider returned an unsuccessful response.');
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new AssistantProviderException('The assistant provider returned an invalid response.');
        }

        $content = $message['content'] ?? null;

        if ($content !== null && (! is_string($content) || mb_strlen($content) > 8000)) {
            throw new AssistantProviderException('The assistant provider returned an invalid response.');
        }

        $rawCalls = $message['tool_calls'] ?? [];

        if (! is_array($rawCalls) || count($rawCalls) > 4) {
            throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
        }

        $toolCalls = [];

        foreach ($rawCalls as $call) {
            $id = data_get($call, 'id');
            $name = data_get($call, 'function.name');
            $rawArguments = data_get($call, 'function.arguments');

            if (data_get($call, 'type') !== 'function'
                || ! is_string($id) || $id === '' || mb_strlen($id) > 200
                || ! is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) !== 1
                || ! is_string($rawArguments) || mb_strlen($rawArguments) > 8000
                || ! str_starts_with(ltrim($rawArguments), '{')) {
                throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
            }

            $arguments = json_decode($rawArguments);

            if (! $arguments instanceof \stdClass) {
                throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
            }

            $toolCalls[] = new AssistantToolCall($id, $name, get_object_vars($arguments), $rawArguments);
        }

        return new AssistantCompletion(is_string($content) ? trim($content) : null, $toolCalls);
    }

    private function validBaseUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
            || ($parts['query'] ?? null) !== null
            || ($parts['fragment'] ?? null) !== null) {
            return false;
        }

        return ! $this->externalProviderConfigured() || strtolower((string) $parts['scheme']) === 'https';
    }

    private function externalProviderConfigured(): bool
    {
        return (bool) config('ai.external_provider', false);
    }
}
