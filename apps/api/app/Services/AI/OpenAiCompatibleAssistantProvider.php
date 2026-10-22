<?php

namespace App\Services\AI;

use App\Contracts\AssistantProvider;
use App\Exceptions\AssistantProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiCompatibleAssistantProvider implements AssistantProvider
{
    private const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

    public function __construct(private readonly AiProviderConfiguration $configuration) {}

    public function isAvailable(): bool
    {
        $configuration = $this->configuration->current();

        $provider = $configuration['provider'];
        $baseUrl = $provider === 'openrouter' ? self::OPENROUTER_BASE_URL : $configuration['base_url'];

        return in_array($provider, ['openai-compatible', 'openrouter'], true)
            && is_string($baseUrl)
            && $this->validBaseUrl($baseUrl, $configuration['external_provider'])
            && is_string($configuration['model'])
            && trim($configuration['model']) !== ''
            && ($provider !== 'openrouter' || (is_string($configuration['api_key']) && trim($configuration['api_key']) !== ''));
    }

    public function isExternal(): bool
    {
        $configuration = $this->configuration->current();

        return $this->isAvailable() && ($configuration['provider'] === 'openrouter' || $configuration['external_provider']);
    }

    public function label(): string
    {
        $configuration = $this->configuration->current();

        if (! $this->isAvailable()) {
            return 'No provider configured';
        }

        return match ($configuration['provider']) {
            'openrouter' => 'OpenRouter',
            default => $this->isExternal() ? 'External OpenAI-compatible provider' : 'Local OpenAI-compatible provider',
        };
    }

    public function complete(AssistantCompletionRequest $request): AssistantCompletion
    {
        if (! $this->isAvailable()) {
            throw new AssistantProviderException('The assistant provider is not configured.');
        }

        $configuration = $this->configuration->current();
        $baseUrl = $configuration['provider'] === 'openrouter' ? self::OPENROUTER_BASE_URL : (string) $configuration['base_url'];
        $client = Http::acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('ai.timeout_seconds', 20));
        $apiKey = $configuration['api_key'];

        if (is_string($apiKey) && $apiKey !== '') {
            $client = $client->withToken($apiKey);
        }

        try {
            $response = $client->post(rtrim($baseUrl, '/').'/chat/completions', [
                'model' => trim((string) $configuration['model']),
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

    private function validBaseUrl(string $url, bool $external): bool
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

        return ! $external || strtolower((string) $parts['scheme']) === 'https';
    }
}
