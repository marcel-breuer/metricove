<?php

namespace Tests\Feature;

use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\McpAccessToken;
use App\Models\ModuleUserPermission;
use App\Models\ReceiptDocument;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Services\Mcp\McpAccessService;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_clients_can_read_the_generated_http_api_contract(): void
    {
        $admin = $this->admin();
        if (! Storage::disk('local')->exists('scribe/openapi.yaml')) {
            $this->artisan('scribe:generate')->assertExitCode(0);
        }

        $this->actingAs($admin, 'web')->get('/docs/api')->assertOk();
        $openApi = $this->actingAs($admin, 'web')->getJson('/docs/api.json')->assertOk();
        $this->assertArrayHasKey('/api/health', $openApi->json('paths'));

        $secret = $this->createToken($admin, ['core:profile:view']);
        $this->app['auth']->forgetGuards();
        $this->withToken($secret)->getJson('/api/profile')->assertUnauthorized();

        $resources = $this->mcp($secret, 'resources/list')->assertOk();
        $this->assertSame(
            ['metricove://api/http/openapi.json'],
            array_column($resources->json('result.resources'), 'uri'),
        );

        $resourceRead = $this->mcp($secret, 'resources/read', [
            'uri' => 'metricove://api/http/openapi.json',
        ]);
        $this->assertSame(200, $resourceRead->getStatusCode(), $resourceRead->getContent());
        $reference = $resourceRead->json('result.contents.0');
        $specification = json_decode($reference['text'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('application/json', $reference['mimeType']);
        $this->assertSame('3.1.0', $specification['openapi']);
        $this->assertArrayHasKey('/api/health', $specification['paths']);
        $this->assertArrayNotHasKey('/api/mcp', $specification['paths']);
        $this->assertSame('cookie', $specification['components']['securitySchemes']['sessionCookie']['in']);
    }

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
        $this->assertContains('summarize_visible_utility_readings', $names);
        $this->assertContains('show_visible_utility_meter', $names);
        $this->assertContains('list_visible_utility_reading_revisions', $names);
        $this->assertNotContains('show_visible_recipe', $names);

        $profileSecret = $this->createToken($admin, ['core:profile:view']);
        $profileNames = array_column($this->mcp($profileSecret, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertSame(['show_current_member_profile'], $profileNames);
        $profile = $this->mcp($profileSecret, 'tools/call', ['name' => 'show_current_member_profile', 'arguments' => []])
            ->assertOk()->json('result.structuredContent.profile');
        $this->assertSame($admin->name, $profile['name']);
        $this->assertArrayNotHasKey('email', $profile);

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

    public function test_non_expiring_tokens_remain_usable_and_revocable(): void
    {
        $admin = $this->admin();
        $this->enableModule($admin, 'utilities');
        $this->grant($admin, 'utilities', ['view' => true]);

        $created = $this->actingAs($admin, 'web')->postJson('/api/profile/mcp/tokens', [
            'name' => 'Long-lived utilities client',
            'scopes' => ['utilities:view'],
            'expires_in_days' => null,
        ])->assertCreated()->assertJsonPath('token.expires_at', null);

        $secret = $created->json('secret');
        $token = McpAccessToken::query()->findOrFail($created->json('token.id'));
        $this->assertNull($token->expires_at);
        $this->assertTrue($token->isUsable());
        $this->assertDatabaseHas('household_audit_events', [
            'action' => 'mcp.token.created',
        ]);

        $this->mcp($secret, 'tools/list')->assertOk();
        $this->actingAs($admin, 'web')->getJson('/api/profile/mcp')
            ->assertOk()
            ->assertJsonPath('tokens.0.expires_at', null);

        $this->actingAs($admin, 'web')->deleteJson('/api/profile/mcp/tokens/'.$token->id)->assertOk();
        $this->assertFalse($token->fresh()->isUsable());
        $this->mcp($secret, 'tools/list')->assertUnauthorized();

        $this->actingAs($admin, 'web')->postJson('/api/profile/mcp/tokens', [
            'name' => 'Invalid long expiry',
            'scopes' => ['utilities:view'],
            'expires_in_days' => 366,
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_in_days');
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
                'value' => '987',
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
        $this->assertStringNotContainsString('987.000000', $storedPreview);
        $this->assertStringNotContainsString('Read from meter display', $storedPreview);

        $this->actingAs($admin, 'web')->getJson('/api/profile/mcp')
            ->assertOk()
            ->assertJsonPath('actions.0.value', '987')
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
        $this->assertSame('987.000000', UtilityReading::query()->firstOrFail()->value);
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

        $this->assertSame(0, HouseholdAuditEvent::query()->where('action', 'like', 'mcp.%')->whereRaw("CAST(details AS TEXT) LIKE '%987.000000%'")->count());
    }

    public function test_recipe_write_requires_member_approval_and_replays_idempotently(): void
    {
        $admin = $this->admin();
        $this->enableModule($admin, 'recipes');
        $this->grant($admin, 'recipes', ['view' => true, 'create' => true]);
        $secret = $this->createToken($admin, ['recipes:create']);
        $tools = $this->mcp($secret, 'tools/list')->assertOk();
        $names = array_column($tools->json('result.tools'), 'name');
        $this->assertContains('preview_create_recipe', $names);
        $this->assertNotContains('preview_update_recipe', $names);
        $this->assertNotContains('preview_delete_recipe', $names);

        $previewResponse = $this->mcp($secret, 'tools/call', [
            'name' => 'preview_create_recipe',
            'arguments' => [
                'title' => 'Approved pasta',
                'instructions' => 'Boil and serve.',
                'servings' => 2,
                'visibility' => 'private',
                'ingredients' => [['name' => 'Pasta', 'quantity' => '250', 'unit' => 'g']],
            ],
        ])->assertOk();
        $preview = $previewResponse->json('result.structuredContent');
        if (! is_array($preview)) {
            $preview = json_decode($previewResponse->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertSame('pending_human_approval', $preview['state']);
        $this->assertDatabaseCount('recipes', 0);
        $this->assertStringNotContainsString('Approved pasta', DB::table('mcp_action_previews')->where('id', $preview['preview_id'])->value('payload'));

        $this->actingAs($admin, 'web')->getJson('/api/profile/mcp')
            ->assertOk()
            ->assertJsonPath('actions.0.action', 'Create a recipe')
            ->assertJsonPath('actions.0.details.title', 'Approved pasta');
        $this->actingAs($admin, 'web')->postJson('/api/profile/mcp/actions/'.$preview['preview_id'].'/approve')->assertOk();
        $idempotencyKey = (string) Str::uuid();
        $firstResponse = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_mcp_change',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => $idempotencyKey],
        ])->assertOk();
        $first = $firstResponse->json('result.structuredContent');
        if (! is_array($first)) {
            $first = json_decode($firstResponse->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertSame(['status' => 'completed'], $first);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseHas('recipe_ingredients', ['name' => 'Pasta', 'quantity' => '250.0000']);

        $replay = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_mcp_change',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => $idempotencyKey],
        ])->assertOk();
        $this->assertSame($first, $replay->json('result.structuredContent'));
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.action.previewed']);
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.action.approved']);
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.action.executed']);
    }

    public function test_notion_recipe_import_tool_is_no_longer_offered(): void
    {
        $admin = $this->admin();
        foreach (['import', 'recipes'] as $module) {
            $this->enableModule($admin, $module);
            $this->grant($admin, $module, ['view' => true, 'create' => true]);
        }

        $this->assertNotContains('import:create', app(McpAccessService::class)->allowedScopes($admin));

        $secret = $this->createToken($admin, ['recipes:create']);
        $names = array_column($this->mcp($secret, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertNotContains('preview_import_notion_recipes', $names);

        $response = $this->mcp($secret, 'tools/call', [
            'name' => 'preview_import_notion_recipes', 'arguments' => ['recipes' => [['source_id' => 'notion-recipe-1', 'title' => 'Lemon pasta']]],
        ])->assertStatus(400);
        $this->assertNotNull($response->json('error'));
        $this->assertDatabaseCount('mcp_action_previews', 0);
        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_notes_mcp_writes_require_approval_and_keep_note_content_out_of_audit(): void
    {
        $admin = $this->admin();
        $this->enableModule($admin, 'notes');
        $this->grant($admin, 'notes', ['view' => true, 'create' => true, 'edit' => true]);
        $secret = $this->createToken($admin, ['notes:view', 'notes:create', 'notes:edit']);

        $names = array_column($this->mcp($secret, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('list_visible_notes', $names);
        $this->assertContains('show_visible_note', $names);
        $this->assertContains('preview_create_note', $names);
        $this->assertContains('preview_update_note', $names);
        $this->assertNotContains('preview_update_note', array_column(
            $this->mcp($this->createToken($admin, ['notes:view']), 'tools/list')->assertOk()->json('result.tools'), 'name',
        ));

        $response = $this->mcp($secret, 'tools/call', [
            'name' => 'preview_create_note',
            'arguments' => ['title' => 'Private MCP note', 'body' => 'Review the pantry list.'],
        ])->assertOk();
        $preview = $response->json('result.structuredContent');
        if (! is_array($preview)) {
            $preview = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertDatabaseCount('notes', 0);
        $this->actingAs($admin, 'web')->postJson('/api/profile/mcp/actions/'.$preview['preview_id'].'/approve')->assertOk();
        $completed = $this->mcp($secret, 'tools/call', [
            'name' => 'complete_approved_mcp_change',
            'arguments' => ['preview_id' => $preview['preview_id'], 'idempotency_key' => (string) Str::uuid()],
        ])->assertOk();
        $this->assertSame('completed', $completed->json('result.structuredContent.status'));
        $this->assertDatabaseCount('notes', 1);
        $this->assertSame(0, HouseholdAuditEvent::query()->where('action', 'like', 'mcp.%')
            ->whereRaw("CAST(details AS TEXT) LIKE '%Review the pantry list.%'")->count());
    }

    public function test_receipt_mcp_read_is_scoped_and_omits_file_links(): void
    {
        $admin = $this->admin();
        $this->enableModule($admin, 'receipts');
        $this->grant($admin, 'receipts', ['view' => true]);
        ReceiptDocument::query()->create([
            'owner_user_id' => $admin->id,
            'is_private' => true,
            'file_path' => 'receipts/private-test.pdf',
            'mime_type' => 'application/pdf',
            'merchant' => 'Local shop',
            'invoice_date' => '2026-11-01',
            'amount' => '12.50',
            'currency' => 'EUR',
            'idempotency_key' => (string) Str::uuid(),
            'file_digest' => str_repeat('a', 64),
            'request_fingerprint' => str_repeat('b', 64),
        ]);

        $secret = $this->createToken($admin, ['receipts:view']);
        $response = $this->mcp($secret, 'tools/call', [
            'name' => 'list_visible_receipts',
            'arguments' => ['search' => 'Local shop'],
        ])->assertOk();
        $receipt = $response->json('result.structuredContent.receipts.0');
        $this->assertSame('Local shop', $receipt['merchant']);
        $this->assertArrayNotHasKey('file_url', $receipt);

        $coreSecret = $this->createToken($admin, ['core:profile:view']);
        $coreTools = array_column($this->mcp($coreSecret, 'tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertNotContains('list_visible_receipts', $coreTools);
    }

    public function test_write_tool_catalog_is_typed_and_gated_by_current_module_grants(): void
    {
        $admin = $this->admin();
        $modules = ['tracking', 'utilities', 'vehicles', 'finance', 'recipes', 'meal_planning', 'pantry', 'shopping', 'analytics', 'tasks', 'notes', 'receipts'];
        foreach ($modules as $module) {
            $this->enableModule($admin, $module);
            $this->grant($admin, $module, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        }

        $scopes = app(McpAccessService::class)->allowedScopes($admin);
        $secret = $this->createToken($admin, $scopes);
        $tools = [];
        $cursor = null;
        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $page = $this->mcp($secret, 'tools/list', $params)->assertOk()->json('result');
            $tools = [...$tools, ...($page['tools'] ?? [])];
            $cursor = $page['nextCursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');
        $names = array_column($tools, 'name');

        foreach ([
            'preview_create_finance_category', 'preview_create_finance_budget', 'preview_create_finance_recurring_entry',
            'preview_create_utility_meter', 'preview_update_utility_meter', 'preview_create_vehicle', 'preview_update_vehicle',
            'preview_add_vehicle_maintenance', 'preview_add_vehicle_insurance', 'preview_add_vehicle_cost',
            'preview_create_recipe', 'preview_create_meal_plan_slot', 'preview_create_pantry_item', 'preview_create_shopping_list',
            'preview_create_analytics_widget', 'preview_create_household_task', 'complete_approved_mcp_change',
            'show_current_member_profile', 'show_visible_recipe', 'list_meal_plan_week',
            'list_visible_tracker_schemas', 'show_visible_tracker_schema', 'list_visible_tracker_entries',
            'list_visible_finance_categories', 'show_visible_utility_meter', 'list_visible_utility_reading_revisions',
            'show_visible_vehicle', 'list_visible_vehicle_odometer_revisions', 'list_visible_pantry_movements',
            'show_visible_shopping_list', 'list_visible_shopping_history', 'list_visible_task_assignees',
            'list_visible_task_history', 'list_visible_analytics_metrics',
            'list_visible_notes', 'show_visible_note', 'list_visible_note_folders', 'list_visible_receipts',
            'preview_create_note', 'preview_update_note', 'preview_archive_note', 'preview_create_note_folder',
        ] as $name) {
            $this->assertContains($name, $names, implode(', ', $names));
        }

        foreach ($tools as $tool) {
            if (str_starts_with($tool['name'], 'preview_')) {
                $this->assertSame(false, $tool['inputSchema']['additionalProperties'] ?? null, $tool['name']);
            }
        }

        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', false);
        $afterDisable = $this->mcp($secret, 'tools/list')->assertOk()->json('result.tools');
        $this->assertNotContains('preview_create_vehicle', array_column($afterDisable, 'name'));
    }

    public function test_import_read_tool_requires_import_and_source_scopes_and_is_bounded(): void
    {
        $admin = $this->admin();
        $this->enableModule($admin, 'import');
        $this->enableModule($admin, 'utilities');
        $this->grant($admin, 'import', ['view' => true]);
        $this->grant($admin, 'utilities', ['view' => true]);
        $meter = UtilityMeter::query()->create([
            'owner_user_id' => null,
            'created_by_user_id' => $admin->id,
            'name' => 'Export meter',
            'meter_type' => 'water',
            'unit' => 'm³',
            'is_private' => false,
        ]);
        foreach (['4.25', '5.50'] as $index => $value) {
            UtilityReading::query()->create([
                'utility_meter_id' => $meter->id,
                'owner_user_id' => null,
                'created_by_user_id' => $admin->id,
                'is_private' => false,
                'reading_date' => '2026-10-'.($index === 0 ? '01' : '20'),
                'value' => $value,
                'note' => 'Reading '.$index,
            ]);
        }

        $missingSourceScope = $this->createToken($admin, ['import:view']);
        $missingTools = $this->mcp($missingSourceScope, 'tools/list')->assertOk()->json('result.tools');
        $this->assertNotContains('list_exportable_utility_readings', array_column($missingTools, 'name'));

        $secret = $this->createToken($admin, ['import:view', 'utilities:view']);
        $result = $this->mcp($secret, 'tools/call', [
            'name' => 'list_exportable_utility_readings',
            'arguments' => ['meter_id' => $meter->id, 'limit' => 1],
        ])->assertOk()->json('result.structuredContent');
        $this->assertSame(1, count($result['readings']));
        $this->assertSame(true, $result['truncated']);
        $this->assertSame('2026-10-01', $result['readings'][0]['reading_date']);
        $this->assertSame('Reading 0', $result['readings'][0]['note']);
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
        $principal = $params['name'] ?? ($method === 'resources/read' ? $params['uri'] ?? null : null);
        if (is_string($principal)) {
            $headers['Mcp-Name'] = $principal;
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
