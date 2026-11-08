<?php

namespace App\Services\Mcp;

use App\Exceptions\ModuleDisabled;
use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\McpAccessToken;
use App\Models\McpActionPreview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class McpWriteWorkflowService
{
    public function __construct(
        private readonly McpAccessService $access,
        private readonly McpWriteWorkflowRegistry $registry,
    ) {}

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function preview(McpAccessToken $token, string $workflowName, array $arguments): array
    {
        $validated = $this->registry->validate($workflowName, $arguments);
        $definition = $this->registry->get($workflowName);
        $summary = isset($definition['preview']) ? ($definition['preview'])($this->access->assertTokenAllows($token, $definition['module'].':'.$definition['capability']), $validated) : null;

        return DB::transaction(function () use ($token, $workflowName, $validated, $definition, $summary): array {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $freshToken = McpAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();
            $actor = $this->authorizeWorkflowToken($freshToken, $workflowName, $definition, $validated);
            $this->registry->authorizeResources($workflowName, $actor, $validated);

            $preview = McpActionPreview::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $actor->id,
                'mcp_access_token_id' => $freshToken->id,
                'action' => 'workflow:'.$workflowName,
                'payload' => ['arguments' => $validated, ...($summary === null ? [] : ['preview' => $summary])],
                'state' => 'pending',
                'expires_at' => now()->addMinutes(10),
            ]);
            $this->audit($actor, 'mcp.action.previewed', $preview, $freshToken, ['module' => $definition['module']]);

            return [
                'preview_id' => $preview->id,
                'state' => 'pending_human_approval',
                'action' => $definition['title'],
                'details' => $validated,
                ...($summary === null ? [] : ['summary' => $summary]),
                'expires_at' => $preview->expires_at->toISOString(),
            ];
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function pendingFor(User $user): array
    {
        return McpActionPreview::query()
            ->where('user_id', $user->id)
            ->where('action', 'like', 'workflow:%')
            ->whereIn('state', ['pending', 'approved'])
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->get()
            ->map(function (McpActionPreview $preview): array {
                $name = substr($preview->action, strlen('workflow:'));
                $workflow = $this->registry->get($name);
                $payload = $preview->payload;

                return [
                    'id' => $preview->id,
                    'action' => $workflow['title'],
                    'state' => $preview->state,
                    'details' => $payload['arguments'] ?? [],
                    'summary' => $payload['preview'] ?? null,
                    'token_name' => $preview->token()->value('name') ?? 'Revoked token',
                    'expires_at' => $preview->expires_at->toISOString(),
                ];
            })
            ->all();
    }

    public function approve(User $user, string $previewId): void
    {
        $unavailable = false;
        DB::transaction(function () use ($user, $previewId, &$unavailable): void {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $preview = McpActionPreview::query()->where('user_id', $user->id)->whereKey($previewId)->lockForUpdate()->firstOrFail();
            $workflowName = $this->workflowName($preview);
            if ($workflowName === null || ! in_array($preview->state, ['pending', 'approved'], true)) {
                $unavailable = true;

                return;
            }

            $definition = $this->registry->get($workflowName);
            $token = McpAccessToken::query()->whereKey($preview->mcp_access_token_id)->lockForUpdate()->firstOrFail();
            $arguments = $this->registry->validate($workflowName, $preview->payload['arguments'] ?? []);
            $actor = $this->authorizeWorkflowToken($token, $workflowName, $definition, $arguments);
            $this->registry->authorizeResources($workflowName, $actor, $arguments);

            if ($preview->expires_at->isPast()) {
                $preview->forceFill(['state' => 'expired'])->save();
                $unavailable = true;

                return;
            }
            if ($preview->state === 'approved') {
                return;
            }

            $preview->forceFill(['state' => 'approved', 'approved_at' => now()])->save();
            $this->audit($actor, 'mcp.action.approved', $preview, $token, ['module' => $definition['module']]);
        }, 3);

        if ($unavailable) {
            throw new AuthorizationException('This action is no longer awaiting approval.');
        }
    }

    public function reject(User $user, string $previewId): void
    {
        DB::transaction(function () use ($user, $previewId): void {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $preview = McpActionPreview::query()->where('user_id', $user->id)->whereKey($previewId)->lockForUpdate()->firstOrFail();
            if ($this->workflowName($preview) === null || ! in_array($preview->state, ['pending', 'approved'], true)) {
                throw new AuthorizationException('This action is no longer awaiting approval.');
            }

            $preview->forceFill(['state' => 'rejected'])->save();
            HouseholdAuditEvent::query()->create([
                'actor_user_id' => $user->id,
                'subject_user_id' => $user->id,
                'action' => 'mcp.action.rejected',
                'details' => ['preview_id' => $preview->id, 'action' => $preview->action],
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function complete(McpAccessToken $token, string $previewId, string $idempotencyKey): array
    {
        if (! Str::isUuid($previewId) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['arguments' => 'The approved action identifier is invalid.']);
        }

        try {
            $result = DB::transaction(function () use ($token, $previewId, $idempotencyKey): ?array {
                HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
                $freshToken = McpAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();
                $preview = McpActionPreview::query()->whereKey($previewId)
                    ->where('mcp_access_token_id', $freshToken->id)->lockForUpdate()->firstOrFail();
                $workflowName = $this->workflowName($preview);
                if ($workflowName === null) {
                    throw new AuthorizationException('The MCP action is unavailable.');
                }

                $definition = $this->registry->get($workflowName);
                $arguments = $this->registry->validate($workflowName, $preview->payload['arguments'] ?? []);
                $actor = $this->authorizeWorkflowToken($freshToken, $workflowName, $definition, $arguments);
                if ($preview->user_id !== $actor->id) {
                    throw new AuthorizationException('The MCP action is unavailable.');
                }
                $this->registry->authorizeResources($workflowName, $actor, $arguments);
                if ($preview->state === 'completed') {
                    if ($preview->idempotency_key !== $idempotencyKey || ! is_array($preview->result)) {
                        throw new AuthorizationException('The MCP action is unavailable.');
                    }

                    return $preview->result;
                }
                if ($preview->state !== 'approved' || $preview->expires_at->isPast()) {
                    if ($preview->state === 'approved') {
                        $preview->forceFill(['state' => 'expired'])->save();
                    }

                    return null;
                }

                $this->registry->execute($workflowName, $actor, $arguments);
                $result = ['status' => 'completed'];
                $preview->forceFill([
                    'state' => 'completed',
                    'idempotency_key' => $idempotencyKey,
                    'result' => $result,
                    'completed_at' => now(),
                ])->save();
                $this->audit($actor, 'mcp.action.executed', $preview, $freshToken, ['module' => $definition['module']]);

                return $result;
            }, 3);

            if (! is_array($result)) {
                throw new AuthorizationException('The approved action has expired.');
            }

            return $result;
        } catch (UniqueConstraintViolationException $exception) {
            throw new AuthorizationException('The MCP action idempotency key has already been used.', 0, $exception);
        }
    }

    private function workflowName(McpActionPreview $preview): ?string
    {
        if (! str_starts_with($preview->action, 'workflow:')) {
            return null;
        }

        return substr($preview->action, strlen('workflow:'));
    }

    /** @param array{module: string, title: string, capabilities: list<string>, required_scopes?: list<string>} $definition */
    private function authorizeWorkflowToken(McpAccessToken $token, string $workflowName, array $definition, array $arguments): User
    {
        $actor = null;
        foreach ($this->registry->requiredCapabilities($workflowName, $arguments) as $capability) {
            try {
                $actor = $this->access->assertTokenAllows($token, $definition['module'].':'.$capability);
                break;
            } catch (AuthorizationException|ModuleDisabled) {
                // The domain service still checks the exact operation and resource capability.
            }
        }

        if (isset($definition['required_scopes'])) {
            foreach ($definition['required_scopes'] as $scope) {
                $this->access->assertTokenAllows($token, $scope);
            }
        }

        if (! $actor instanceof User) {
            throw new AuthorizationException('The MCP action is unavailable.');
        }

        foreach ($this->registry->additionalTokenScopes($workflowName, $arguments) as $scope) {
            $this->access->assertTokenAllows($token, $scope);
        }

        return $actor;
    }

    /** @param array<string, mixed> $extra */
    private function audit(User $actor, string $action, McpActionPreview $preview, McpAccessToken $token, array $extra = []): void
    {
        HouseholdAuditEvent::query()->create([
            'actor_user_id' => $actor->id,
            'subject_user_id' => $actor->id,
            'action' => $action,
            'details' => array_merge(['preview_id' => $preview->id, 'token_id' => $token->id, 'action' => $preview->action], $extra),
        ]);
    }
}
