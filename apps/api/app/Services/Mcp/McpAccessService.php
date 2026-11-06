<?php

namespace App\Services\Mcp;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mcp\Server\Authorization\AccessToken;

class McpAccessService
{
    /** @var list<string> */
    private const MCP_VIEW_MODULES = [
        'tracking', 'utilities', 'vehicles', 'finance', 'recipes', 'meal_planning',
        'pantry', 'shopping', 'analytics', 'tasks', 'import', 'notes', 'receipts',
    ];

    private const CORE_PROFILE_SCOPE = 'core:profile:view';

    /** @var array<string, array{module: string, capability: ModuleCapability}> */
    private const SCOPES = [
        'tracking:view' => ['module' => 'tracking', 'capability' => ModuleCapability::View],
        'utilities:view' => ['module' => 'utilities', 'capability' => ModuleCapability::View],
        'utilities:create' => ['module' => 'utilities', 'capability' => ModuleCapability::Create],
        'utilities:edit' => ['module' => 'utilities', 'capability' => ModuleCapability::Edit],
        'vehicles:view' => ['module' => 'vehicles', 'capability' => ModuleCapability::View],
        'vehicles:create' => ['module' => 'vehicles', 'capability' => ModuleCapability::Create],
        'vehicles:edit' => ['module' => 'vehicles', 'capability' => ModuleCapability::Edit],
        'finance:view' => ['module' => 'finance', 'capability' => ModuleCapability::View],
        'finance:create' => ['module' => 'finance', 'capability' => ModuleCapability::Create],
        'finance:edit' => ['module' => 'finance', 'capability' => ModuleCapability::Edit],
        'recipes:view' => ['module' => 'recipes', 'capability' => ModuleCapability::View],
        'recipes:create' => ['module' => 'recipes', 'capability' => ModuleCapability::Create],
        'recipes:edit' => ['module' => 'recipes', 'capability' => ModuleCapability::Edit],
        'meal_planning:view' => ['module' => 'meal_planning', 'capability' => ModuleCapability::View],
        'meal_planning:create' => ['module' => 'meal_planning', 'capability' => ModuleCapability::Create],
        'meal_planning:edit' => ['module' => 'meal_planning', 'capability' => ModuleCapability::Edit],
        'pantry:view' => ['module' => 'pantry', 'capability' => ModuleCapability::View],
        'pantry:create' => ['module' => 'pantry', 'capability' => ModuleCapability::Create],
        'pantry:edit' => ['module' => 'pantry', 'capability' => ModuleCapability::Edit],
        'shopping:view' => ['module' => 'shopping', 'capability' => ModuleCapability::View],
        'shopping:create' => ['module' => 'shopping', 'capability' => ModuleCapability::Create],
        'shopping:edit' => ['module' => 'shopping', 'capability' => ModuleCapability::Edit],
        'analytics:view' => ['module' => 'analytics', 'capability' => ModuleCapability::View],
        'analytics:create' => ['module' => 'analytics', 'capability' => ModuleCapability::Create],
        'analytics:edit' => ['module' => 'analytics', 'capability' => ModuleCapability::Edit],
        'tasks:view' => ['module' => 'tasks', 'capability' => ModuleCapability::View],
        'import:view' => ['module' => 'import', 'capability' => ModuleCapability::View],
        'import:create' => ['module' => 'import', 'capability' => ModuleCapability::Create],
        'receipts:view' => ['module' => 'receipts', 'capability' => ModuleCapability::View],
        'notes:view' => ['module' => 'notes', 'capability' => ModuleCapability::View],
        'notes:create' => ['module' => 'notes', 'capability' => ModuleCapability::Create],
        'notes:edit' => ['module' => 'notes', 'capability' => ModuleCapability::Edit],
        'notes:manage' => ['module' => 'notes', 'capability' => ModuleCapability::Manage],
        'tasks:create' => ['module' => 'tasks', 'capability' => ModuleCapability::Create],
        'tasks:edit' => ['module' => 'tasks', 'capability' => ModuleCapability::Edit],
        'tracking:create' => ['module' => 'tracking', 'capability' => ModuleCapability::Create],
        'tracking:edit' => ['module' => 'tracking', 'capability' => ModuleCapability::Edit],
        'tracking:manage' => ['module' => 'tracking', 'capability' => ModuleCapability::Manage],
        'utilities:manage' => ['module' => 'utilities', 'capability' => ModuleCapability::Manage],
        'vehicles:manage' => ['module' => 'vehicles', 'capability' => ModuleCapability::Manage],
        'finance:manage' => ['module' => 'finance', 'capability' => ModuleCapability::Manage],
        'recipes:manage' => ['module' => 'recipes', 'capability' => ModuleCapability::Manage],
        'meal_planning:manage' => ['module' => 'meal_planning', 'capability' => ModuleCapability::Manage],
        'pantry:manage' => ['module' => 'pantry', 'capability' => ModuleCapability::Manage],
        'shopping:manage' => ['module' => 'shopping', 'capability' => ModuleCapability::Manage],
        'analytics:manage' => ['module' => 'analytics', 'capability' => ModuleCapability::Manage],
        'tasks:manage' => ['module' => 'tasks', 'capability' => ModuleCapability::Manage],
    ];

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<string> */
    public function allowedScopes(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        $allowed = [self::CORE_PROFILE_SCOPE];
        foreach (self::MCP_VIEW_MODULES as $module) {
            try {
                $this->access->authorize($user, $module, ModuleCapability::View);
                $allowed[] = $module.':view';
            } catch (AuthorizationException|ModuleDisabled) {
                // Module state and member grants are checked again for every request.
            }
        }

        foreach (self::SCOPES as $scope => $definition) {
            if ($definition['capability'] === ModuleCapability::View || $scope === 'utilities:create') {
                continue;
            }
            try {
                $this->access->authorize($user, $definition['module'], $definition['capability']);
                $allowed[] = $scope;
            } catch (AuthorizationException|ModuleDisabled) {
                // Only currently granted write capabilities are offered for token creation.
            }
        }

        try {
            $this->access->authorize($user, 'utilities', ModuleCapability::Create);
            $this->access->authorize($user, 'utilities', ModuleCapability::View);
            $allowed[] = 'utilities:create';
        } catch (AuthorizationException|ModuleDisabled) {
            // Write scopes are exposed only when an approved MCP operation exists.
        }

        return $allowed;
    }

    /** @param list<string> $scopes
     * @return array{token: McpAccessToken, secret: string}
     */
    public function createToken(User $user, string $name, array $scopes, ?int $expiresInDays): array
    {
        $scopes = array_values(array_unique($scopes));
        $allowed = $this->allowedScopes($user);

        if ($scopes === [] || array_diff($scopes, $allowed) !== []) {
            throw ValidationException::withMessages(['scopes' => 'Select only scopes currently available to your account.']);
        }

        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9 ._-]{0,99}\z/', $name) !== 1) {
            throw ValidationException::withMessages(['name' => 'Enter a token name using letters, numbers, spaces, dots, underscores or hyphens.']);
        }

        if ($expiresInDays !== null && ($expiresInDays < 1 || $expiresInDays > 365)) {
            throw ValidationException::withMessages(['expires_in_days' => 'Token expiry must be between 1 and 365 days.']);
        }

        $secret = 'mtv_'.bin2hex(random_bytes(32));

        $token = DB::transaction(function () use ($user, $name, $scopes, $expiresInDays, $secret): McpAccessToken {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_unless($actor instanceof User && $actor->is_active, 403);

            $currentAllowed = $this->allowedScopes($actor);
            if (array_diff($scopes, $currentAllowed) !== []) {
                throw ValidationException::withMessages(['scopes' => 'Your module access changed. Reload and select available scopes again.']);
            }

            $token = McpAccessToken::query()->create([
                'user_id' => $actor->id,
                'name' => trim($name),
                'token_hash' => hash('sha256', $secret),
                'scopes' => $scopes,
                'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
            ]);

            HouseholdAuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'subject_user_id' => $actor->id,
                'action' => 'mcp.token.created',
                'details' => ['token_id' => $token->id, 'scopes' => $scopes, 'expires_at' => $token->expires_at?->toISOString()],
            ]);

            return $token;
        }, 3);

        return ['token' => $token, 'secret' => $secret];
    }

    public function revokeToken(User $user, int $tokenId): void
    {
        DB::transaction(function () use ($user, $tokenId): void {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $token = McpAccessToken::query()
                ->where('user_id', $user->id)
                ->whereKey($tokenId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($token->revoked_at !== null) {
                return;
            }

            $token->forceFill(['revoked_at' => now()])->save();
            HouseholdAuditEvent::query()->create([
                'actor_user_id' => $user->id,
                'subject_user_id' => $user->id,
                'action' => 'mcp.token.revoked',
                'details' => ['token_id' => $token->id],
            ]);
        }, 3);
    }

    public function assertTokenAllows(McpAccessToken $token, string $scope): User
    {
        $token->refresh();
        $actor = User::query()->whereKey($token->user_id)->where('is_active', true)->first();

        if (! $token->isUsable() || ! $actor instanceof User || ! in_array($scope, $token->scopes ?? [], true)) {
            throw new AuthorizationException('The MCP action is unavailable.');
        }

        $definition = self::SCOPES[$scope] ?? null;
        if ($scope === self::CORE_PROFILE_SCOPE) {
            return $actor;
        }

        if ($definition === null) {
            throw new AuthorizationException('The MCP action is unavailable.');
        }

        $this->access->authorize($actor, $definition['module'], $definition['capability']);
        if ($scope === 'utilities:create') {
            $this->access->authorize($actor, $definition['module'], ModuleCapability::View);
        }

        return $actor;
    }

    /**
     * Run an MCP operation in the same lock order as module, permission and token mutations.
     *
     * @template TResult
     *
     * @param  callable(User): TResult  $operation
     * @return TResult
     */
    public function withAuthorizedToken(McpAccessToken $token, string $scope, callable $operation): mixed
    {
        return DB::transaction(function () use ($token, $scope, $operation): mixed {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $freshToken = McpAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();
            $actor = $this->assertTokenAllows($freshToken, $scope);

            return $operation($actor);
        }, 3);
    }

    public function sdkAccessToken(McpAccessToken $token, User $actor): AccessToken
    {
        return new AccessToken(
            array_values(array_intersect($token->scopes ?? [], $this->allowedScopes($actor))),
            ['sub' => (string) $actor->id, 'client_id' => 'metricove-mcp', 'mcp_token_id' => $token->id],
        );
    }

    /** @return array{id: int, name: string, scopes: list<string>, expires_at: ?string, revoked_at: ?string, last_used_at: ?string, created_at: ?string} */
    public function presentToken(McpAccessToken $token): array
    {
        return [
            'id' => (int) $token->id,
            'name' => $token->name,
            'scopes' => $token->scopes ?? [],
            'expires_at' => $token->expires_at?->toISOString(),
            'revoked_at' => $token->revoked_at?->toISOString(),
            'last_used_at' => $token->last_used_at?->toISOString(),
            'created_at' => $token->created_at?->toISOString(),
        ];
    }
}
