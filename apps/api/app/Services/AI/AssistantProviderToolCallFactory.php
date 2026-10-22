<?php

namespace App\Services\AI;

use App\Exceptions\AssistantProviderException;
use Illuminate\Support\Str;

class AssistantProviderToolCallFactory
{
    /** @param array<string, mixed>|\stdClass $arguments */
    public static function make(mixed $id, mixed $name, mixed $arguments, bool $allowMissingId = false): AssistantToolCall
    {
        if ($allowMissingId && (! is_string($id) || $id === '')) {
            $id = (string) Str::uuid();
        }

        if (! is_string($id) || $id === '' || mb_strlen($id) > 200
            || ! is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) !== 1
            || (! is_array($arguments) && ! $arguments instanceof \stdClass)) {
            throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
        }

        $arguments = $arguments instanceof \stdClass ? get_object_vars($arguments) : $arguments;
        if ($arguments !== [] && array_is_list($arguments)) {
            throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
        }

        $rawArguments = json_encode((object) $arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (mb_strlen($rawArguments) > 8000) {
            throw new AssistantProviderException('The assistant provider returned an invalid tool response.');
        }

        return new AssistantToolCall($id, $name, $arguments, $rawArguments);
    }
}
