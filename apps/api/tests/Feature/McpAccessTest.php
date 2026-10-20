<?php

namespace Tests\Feature;

use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\McpAccessToken;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_hashed_scoped_revocable_and_modules_are_rechecked(): void
    {
        $admin = $this->admin();
        $this->enableModule($admin, 'utilities');
        $this->grant($admin, 'utilities', ['view' => true, 'create' => true]);

        $created = $this->actingAs($admin, 'web')->postJson('/api/profile/mcp/tokens', [
            'name' => 'Read utilities',
            'scopes' => ['utilities:view'],
            'expires_in_days' => 90,
        ])->assertCreated();
        $secret = $created->json('secret');
        $this->assertIsString($secret);
        $this->assertDatabaseMissing('mcp_access_tokens', ['token_hash' => $secret]);
        $stored = McpAccessToken::query()->findOrFail($created->json('token.id'));
        $this->assertSame(hash('sha256', $secret), $stored->token_hash);

        $this->postJson('/api/mcp', [])->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $list = $this->mcp($secret, 'tools/list')->assertOk();
        $this->assertStringContainsString('no-store', $list->headers->get('Cache-Control', ''));
        $names = array_column($list->json('result.tools'), 'name');
        $this->assertSame(['summarize_visible_utility_readings'], $names);

        $this->enableModule($admin, 'finance');
        $this->grant($admin, 'finance', ['view' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', false);
        $afterDisable = $this->mcp($secret, 'tools/list')->assertOk();
        $this->assertSame([], $afterDisable->json('result.tools'));

        $this->actingAs($admin, 'web')->getJson('/api/profile/mcp')
            ->assertOk()
            ->assertJsonMissing(['secret' => $secret]);
        $this->actingAs($admin, 'web')->deleteJson('/api/profile/mcp/tokens/'.$stored->id)->assertOk();
        $this->mcp($secret, 'tools/list')->assertUnauthorized();
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.token.created']);
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.token.revoked']);
    }

    public function test_utility_write_requires_profile_approval_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $this->enableModule($admin, 'utilities');
        $this->grant($admin, 'utilities', ['view' => true, 'create' => true]);
        $meter = UtilityMeter::query()->create([
            'owner_user_id' => null,
            'created_by_user_id' => $admin->id,
            'name' => 'Kitchen electricity',
            'meter_type' => 'electricity',
            'unit' => 'kWh',
            'is_private' => false,
        ]);
        $secret = $this->createToken($admin, ['utilities:create']);
        $previewResponse = $this->mcp($secret, 'tools/call', [
            'name' => 'preview_utility_reading',
            'arguments' => [
                'meter_id' => $meter->id,
                'reading_date' => '2026-10-20',
                'value' => '987.2501',
                'note' => 'Read from meter display',
            ],
        ])->assertOk();
        $preview = $previewResponse->json('result.structuredContent');
        if (! is_array($preview)) {
            $preview = json_decode($previewResponse->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        }

        $this->assertSame('pending_human_approval', $preview['state']);
        $this->assertDatabaseCount('utility_readings', 0);
        $storedPreview = DB::table('mcp_action_previews')->where('id', $preview['preview_id'])->value('payload');
        $this->assertIsString($storedPreview);
        $this->assertStringNotContainsString('987.2501', $storedPreview);
        $this->assertStringNotContainsString('Read from meter display', $storedPreview);

        $this->actingAs($admin, 'web')->getJson('/api/profile/mcp')
            ->assertOk()
            ->assertJsonPath('actions.0.value', '987.2501')
            ->assertJsonPath('actions.0.note', 'Read from meter display');
        $unapproved = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_utility_reading',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => (string) Str::uuid()],
        ])->assertOk();
        $this->assertTrue($unapproved->json('result.isError') === true);
        $this->assertDatabaseCount('utility_readings', 0);

        $this->actingAs($admin, 'web')->postJson('/api/profile/mcp/actions/'.$preview['preview_id'].'/approve')->assertOk();
        $idempotencyKey = (string) Str::uuid();
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', false);
        $disabledCompletion = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_utility_reading',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => $idempotencyKey],
        ]);
        $this->assertTrue($disabledCompletion->getStatusCode() !== 200 || $disabledCompletion->json('result.isError') === true);
        $this->assertDatabaseCount('utility_readings', 0);
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', true);
        $completion = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_utility_reading',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => $idempotencyKey],
        ])->assertOk();
        $result = $completion->json('result.structuredContent');
        if (! is_array($result)) {
            $result = json_decode($completion->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        }

        $this->assertSame('completed', $result['status']);
        $this->assertSame('987.250100', UtilityReading::query()->firstOrFail()->value);
        $this->assertSame('Read from meter display', UtilityReading::query()->firstOrFail()->note);
        $this->assertDatabaseCount('utility_readings', 1);

        $replay = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_utility_reading',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => $idempotencyKey],
        ])->assertOk();
        $this->assertSame($result, $replay->json('result.structuredContent'));
        $this->assertDatabaseCount('utility_readings', 1);
        $differentKeyReplay = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_utility_reading',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => (string) Str::uuid()],
        ])->assertOk();
        $this->assertTrue($differentKeyReplay->json('result.isError') === true);
        $this->assertDatabaseCount('utility_readings', 1);
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.action.previewed']);
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.action.approved']);
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.action.executed']);

        $this->assertSame(0, HouseholdAuditEvent::query()->where('action', 'like', 'mcp.%')->whereRaw("CAST(details AS TEXT) LIKE '%987.2501%'")->count());
    }

    public function test_mcp_read_tools_respect_resource_privacy_and_token_scope(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enableModule($admin, 'utilities');
        $this->grant($admin, 'utilities', ['view' => true]);
        $this->grant($owner, 'utilities', ['view' => true]);

        UtilityMeter::query()->create([
            'owner_user_id' => $admin->id,
            'created_by_user_id' => $admin->id,
            'name' => 'Private water meter',
            'meter_type' => 'water',
            'unit' => 'm³',
            'is_private' => true,
        ]);
        $sharedMeter = UtilityMeter::query()->create([
            'owner_user_id' => null,
            'created_by_user_id' => $admin->id,
            'name' => 'Shared gas meter',
            'meter_type' => 'gas',
            'unit' => 'm³',
            'is_private' => false,
        ]);
        UtilityReading::query()->create([
            'utility_meter_id' => $sharedMeter->id,
            'owner_user_id' => null,
            'created_by_user_id' => $admin->id,
            'is_private' => false,
            'reading_date' => '2026-10-20',
            'value' => '9.25',
            'note' => 'This note is private to the household UI.',
        ]);

        $secret = $this->createToken($owner, ['utilities:view']);
        $read = $this->mcp($secret, 'tools/call', [
            'name' => 'summarize_visible_utility_readings',
            'arguments' => [],
        ])->assertOk();
        $content = json_encode($read->json('result.structuredContent') ?? $read->json('result.content'), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Shared gas meter', $content);
        $this->assertStringNotContainsString('Private water meter', $content);
        $this->assertStringNotContainsString('This note is private', $content);
        $listed = $this->mcp($secret, 'tools/list')->assertOk();
        $this->assertNotContains('summarize_finance_month', array_column($listed->json('result.tools'), 'name'));
        $denied = $this->mcp($secret, 'tools/call', [
            'name' => 'summarize_finance_month',
            'arguments' => ['month' => '2026-10'],
        ]);
        $this->assertTrue($denied->getStatusCode() !== 200 || $denied->json('result.isError') === true);
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create(['is_admin' => true, 'is_active' => true]);
    }

    private function enableModule(User $admin, string $module): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, $module, true);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $user, string $module, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $user->id, 'module_key' => $module],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }

    /** @param list<string> $scopes */
    private function createToken(User $user, array $scopes): string
    {
        return $this->actingAs($user, 'web')->postJson('/api/profile/mcp/tokens', [
            'name' => 'MCP test client',
            'scopes' => $scopes,
            'expires_in_days' => 30,
        ])->assertCreated()->json('secret');
    }

    /** @param array<string, mixed> $params */
    private function mcp(string $secret, string $method, array $params = []): TestResponse
    {
        $metadata = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
        $params['_meta'] = $metadata;

        $headers = [
            'Authorization' => 'Bearer '.$secret,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        if (is_string($params['name'] ?? null)) {
            $headers['Mcp-Name'] = $params['name'];
        }

        $response = $this->withHeaders($headers)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => (string) Str::uuid(),
            'method' => $method,
            'params' => $params,
        ]);

        return $response;
    }
}
