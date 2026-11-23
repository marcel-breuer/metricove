<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** MCP tools that mirror REST operations: checklists, deletions, corrections, reminders and discovery lists. */
class McpOperationalCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_exposes_the_approval_guide_and_prompts_without_data(): void
    {
        $admin = $this->admin();
        $secret = $this->createToken($admin, ['core:profile:view']);

        $resources = $this->mcp($secret, 'resources/list')->assertOk()->json('result.resources');
        $this->assertContains('metricove://mcp/approval-workflow', array_column($resources, 'uri'));
        $guide = $this->mcp($secret, 'resources/read', ['uri' => 'metricove://mcp/approval-workflow'])->assertOk();
        $this->assertStringContainsString('complete_approved_mcp_change', $guide->json('result.contents.0.text'));

        $prompts = $this->mcp($secret, 'prompts/list')->assertOk()->json('result.prompts');
        $this->assertEqualsCanonicalizing(
            ['weekly_household_review', 'propose_change_with_approval'],
            array_column($prompts, 'name'),
        );
    }

    public function test_reminders_read_tool_requires_its_core_scope_and_stays_personal(): void
    {
        $admin = $this->admin();
        $withoutScope = $this->createToken($admin, ['core:profile:view']);
        $this->assertNotContains('list_visible_reminders', $this->toolNames($withoutScope));
        $this->assertToolRejected($this->mcp($withoutScope, 'tools/call', ['name' => 'list_visible_reminders', 'arguments' => (object) []]));

        $secret = $this->createToken($admin, ['core:reminders:view']);
        $this->assertContains('list_visible_reminders', $this->toolNames($secret));
        $result = $this->structured($this->mcp($secret, 'tools/call', ['name' => 'list_visible_reminders', 'arguments' => (object) []])->assertOk());
        $this->assertSame([], $result['reminders']);
        $this->assertSame(0, $result['unread_count']);
    }

    public function test_discovery_reads_respect_privacy_scope_and_pagination_limits(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        foreach ([$admin, $member] as $user) {
            $this->grant($user, 'vehicles', ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        }
        $this->enable($admin, 'vehicles');
        $privateVehicle = $this->createVehicle($admin, 'private', 'Admin only car');
        $sharedVehicle = $this->createVehicle($admin, 'household', 'Shared car');
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$sharedVehicle}/maintenance", [
            'title' => 'Winter tyres', 'service_type' => 'tires', 'occurred_on' => '2026-10-01',
        ])->assertCreated();
        foreach (['2026-10-01' => '1000', '2026-10-02' => '1100'] as $date => $value) {
            $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$sharedVehicle}/odometer-readings", [
                'reading_date' => $date, 'value' => $value,
            ])->assertCreated();
        }

        $secret = $this->createToken($member, ['vehicles:view']);
        $vehicles = $this->structured($this->tool($secret, 'list_visible_vehicles', []));
        $this->assertSame(['Shared car'], array_column($vehicles['vehicles'], 'name'));

        $page = $this->structured($this->tool($secret, 'list_visible_vehicle_odometer_readings', ['vehicle_id' => $sharedVehicle, 'limit' => 1]));
        $this->assertCount(1, $page['odometer_readings']);
        $this->assertTrue($page['has_more_odometer_readings']);
        $records = $this->structured($this->tool($secret, 'list_visible_vehicle_records', ['vehicle_id' => $sharedVehicle, 'type' => 'maintenance']));
        $this->assertSame('Winter tyres', $records['maintenance_records'][0]['title']);
        $this->assertToolRejected($this->tool($secret, 'list_visible_vehicle_records', ['vehicle_id' => $privateVehicle, 'type' => 'maintenance']));
        $this->assertToolRejected($this->tool($secret, 'list_visible_vehicle_records', ['vehicle_id' => $sharedVehicle, 'type' => 'invoices']));
        $this->assertToolRejected($this->tool($secret, 'list_visible_vehicle_records', ['vehicle_id' => $sharedVehicle, 'type' => 'costs', 'limit' => 500]));

    }

    public function test_task_checklist_writes_need_approval_scope_and_the_current_version(): void
    {
        $admin = $this->admin();
        $this->grant($admin, 'tasks', ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enable($admin, 'tasks');
        $taskId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Prepare the trip', 'visibility' => 'private', 'timezone' => 'Europe/Berlin', 'priority' => 'medium',
        ])->assertCreated()->json('task.id');

        $readOnly = $this->createToken($admin, ['tasks:view']);
        $this->assertNotContains('preview_add_task_checklist_item', $this->toolNames($readOnly));
        $secret = $this->createToken($admin, ['tasks:view', 'tasks:edit']);
        foreach (['preview_add_task_checklist_item', 'preview_update_task_checklist_item', 'preview_set_task_checklist_item_completed', 'preview_reorder_task_checklist_items', 'preview_delete_task_checklist_item'] as $name) {
            $this->assertContains($name, $this->toolNames($secret));
        }

        $preview = $this->preview($secret, 'preview_add_task_checklist_item', [
            'task_id' => $taskId, 'expected_version' => 1, 'title' => 'Pack passports', 'reminder_lead_minutes' => 0,
        ]);
        $this->assertDatabaseCount('household_task_checklist_items', 0);
        $this->assertToolRejected($this->complete($secret, $preview, expectSuccess: false));
        $this->approve($admin, $preview);
        $this->complete($secret, $preview);
        $this->assertDatabaseHas('household_task_checklist_items', ['title' => 'Pack passports']);

        $itemId = (int) $this->actingAs($admin, 'web')->getJson('/api/tasks')->json('tasks.0.checklist_items.0.id');
        $stale = $this->preview($secret, 'preview_set_task_checklist_item_completed', [
            'task_id' => $taskId, 'item_id' => $itemId, 'expected_version' => 1, 'completed' => true,
        ]);
        $this->approve($admin, $stale);
        $this->assertToolRejected($this->complete($secret, $stale, expectSuccess: false));
        $this->assertDatabaseHas('household_task_checklist_items', ['id' => $itemId, 'title' => 'Pack passports']);

        $this->assertToolRejected($this->tool($secret, 'preview_update_task_checklist_item', ['task_id' => $taskId, 'item_id' => $itemId, 'expected_version' => 2]));
        $this->assertToolRejected($this->tool($secret, 'preview_add_task_checklist_item', ['task_id' => $taskId, 'expected_version' => 2, 'title' => 'x', 'reminder_lead_minutes' => 0, 'extra' => 'nope']));

        $deletion = $this->preview($secret, 'preview_delete_task_checklist_item', [
            'task_id' => $taskId, 'item_id' => $itemId, 'expected_version' => 2,
        ]);
        $this->approve($admin, $deletion);
        $this->complete($secret, $deletion);
        $this->assertDatabaseCount('household_task_checklist_items', 0);
    }

    public function test_task_checklist_tools_do_not_touch_other_members_private_tasks(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        foreach ([$admin, $other] as $user) {
            $this->grant($user, 'tasks', ['view' => true, 'create' => true, 'edit' => true]);
        }
        $this->enable($admin, 'tasks');
        $taskId = (int) $this->actingAs($other, 'web')->postJson('/api/tasks', [
            'title' => 'Private errand', 'visibility' => 'private', 'timezone' => 'UTC', 'priority' => 'low',
        ])->assertCreated()->json('task.id');

        $secret = $this->createToken($admin, ['tasks:view', 'tasks:edit']);
        $response = $this->tool($secret, 'preview_add_task_checklist_item', [
            'task_id' => $taskId, 'expected_version' => 1, 'title' => 'Peek', 'reminder_lead_minutes' => 0,
        ]);
        $this->assertToolRejected($response);
        $this->assertDatabaseCount('household_task_checklist_items', 0);
    }

    public function test_record_deletions_and_cost_updates_are_scoped_approved_and_audited(): void
    {
        $admin = $this->admin();
        $this->grant($admin, 'vehicles', ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($admin, 'utilities', ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enable($admin, 'vehicles');
        $this->enable($admin, 'utilities');
        $vehicleId = $this->createVehicle($admin, 'household', 'Family car');
        $costId = (int) $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'tax', 'amount' => '120.00', 'currency' => 'EUR', 'period_start' => '2026-10-01', 'period' => 'yearly',
        ])->assertCreated()->json('cost.id');
        $maintenanceId = (int) $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/maintenance", [
            'title' => 'Oil change', 'service_type' => 'service', 'occurred_on' => '2026-10-02',
        ])->assertCreated()->json('maintenance_record.id');
        $odometerId = (int) $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => '2026-10-03', 'value' => '5000',
        ])->assertCreated()->json('reading.id');
        $meterId = (int) $this->actingAs($admin, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Cellar water', 'meter_type' => 'water', 'visibility' => 'household',
        ])->assertCreated()->json('meter.id');
        $readingId = (int) $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-04', 'value' => '12',
        ])->assertCreated()->json('reading.id');

        $viewOnly = $this->createToken($admin, ['vehicles:view', 'utilities:view']);
        foreach (['preview_delete_vehicle_record', 'preview_delete_vehicle_odometer_reading', 'preview_update_vehicle_cost', 'preview_delete_utility_reading'] as $name) {
            $this->assertNotContains($name, $this->toolNames($viewOnly));
        }
        $secret = $this->createToken($admin, ['vehicles:view', 'vehicles:edit', 'utilities:view', 'utilities:edit']);

        $update = $this->preview($secret, 'preview_update_vehicle_cost', [
            'cost_id' => $costId, 'category' => 'tax', 'amount' => '135.50', 'currency' => 'EUR',
            'period_start' => '2026-10-01', 'period' => 'yearly', 'description' => 'Corrected tax',
        ]);
        $this->assertDatabaseHas('vehicle_costs', ['id' => $costId, 'amount' => '120.00']);
        $this->approve($admin, $update);
        $this->complete($secret, $update);
        $this->assertDatabaseHas('vehicle_costs', ['id' => $costId, 'amount' => '135.50', 'description' => 'Corrected tax']);

        foreach ([
            ['preview_delete_vehicle_record', ['type' => 'costs', 'record_id' => $costId], 'vehicle_costs', $costId],
            ['preview_delete_vehicle_record', ['type' => 'maintenance', 'record_id' => $maintenanceId], 'vehicle_maintenance_records', $maintenanceId],
            ['preview_delete_vehicle_odometer_reading', ['reading_id' => $odometerId], 'vehicle_odometer_readings', $odometerId],
            ['preview_delete_utility_reading', ['reading_id' => $readingId], 'utility_readings', $readingId],
        ] as [$tool, $arguments, $table, $id]) {
            $preview = $this->preview($secret, $tool, $arguments);
            $this->assertDatabaseHas($table, ['id' => $id]);
            $this->approve($admin, $preview);
            $this->complete($secret, $preview);
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        $this->assertDatabaseHas('household_audit_events', ['action' => 'mcp.action.executed']);
    }

    public function test_deletions_fail_after_scope_or_module_access_is_lost(): void
    {
        $admin = $this->admin();
        $this->grant($admin, 'utilities', ['view' => true, 'create' => true, 'edit' => true]);
        $this->enable($admin, 'utilities');
        $meterId = (int) $this->actingAs($admin, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Garden water', 'meter_type' => 'water', 'visibility' => 'private',
        ])->assertCreated()->json('meter.id');
        $readingId = (int) $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-04', 'value' => '2',
        ])->assertCreated()->json('reading.id');
        $secret = $this->createToken($admin, ['utilities:view', 'utilities:edit']);

        $preview = $this->preview($secret, 'preview_delete_utility_reading', ['reading_id' => $readingId]);
        $this->approve($admin, $preview);
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', false);

        $this->assertToolRejected($this->complete($secret, $preview, expectSuccess: false));
        $this->assertDatabaseHas('utility_readings', ['id' => $readingId]);
        $this->assertNotContains('preview_delete_utility_reading', $this->toolNames($secret));
    }

    public function test_copying_a_meal_plan_week_is_a_gated_approved_write(): void
    {
        $admin = $this->admin();
        $this->grant($admin, 'meal_planning', ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enable($admin, 'meal_planning');
        $this->actingAs($admin, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'dinner', 'dish_name' => 'Lemon pasta', 'servings' => 4,
        ])->assertCreated();

        $this->assertNotContains('preview_copy_previous_meal_plan_week', $this->toolNames($this->createToken($admin, ['meal_planning:view'])));
        $secret = $this->createToken($admin, ['meal_planning:view', 'meal_planning:create']);
        $this->assertToolRejected($this->tool($secret, 'preview_copy_previous_meal_plan_week', ['week_start' => '2026-10-27']));

        $preview = $this->preview($secret, 'preview_copy_previous_meal_plan_week', ['week_start' => '2026-10-26']);
        $this->assertDatabaseCount('meal_plan_slots', 1);
        $this->approve($admin, $preview);
        $this->complete($secret, $preview);
        $this->assertDatabaseCount('meal_plan_slots', 2);
        $this->assertDatabaseHas('meal_plan_slots', ['planned_for' => '2026-10-26', 'dish_name' => 'Lemon pasta']);
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create(['is_admin' => true, 'is_active' => true]);
    }

    private function enable(User $admin, string $module): void
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

    private function createVehicle(User $member, string $visibility, string $name): int
    {
        return (int) $this->actingAs($member, 'web')->postJson('/api/vehicles', [
            'name' => $name, 'make' => 'Volvo', 'model' => 'V60', 'model_year' => 2022,
            'fuel_type' => 'diesel', 'odometer_unit' => 'km', 'visibility' => $visibility,
        ])->assertCreated()->json('vehicle.id');
    }

    /** @param list<string> $scopes */
    private function createToken(User $user, array $scopes): string
    {
        return $this->actingAs($user, 'web')->postJson('/api/profile/mcp/tokens', [
            'name' => 'MCP coverage client', 'scopes' => $scopes, 'expires_in_days' => 30,
        ])->assertCreated()->json('secret');
    }

    /** @return list<string> */
    private function toolNames(string $secret): array
    {
        $names = [];
        $cursor = null;
        do {
            $page = $this->mcp($secret, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor])->assertOk()->json('result');
            $names = [...$names, ...array_column($page['tools'] ?? [], 'name')];
            $cursor = $page['nextCursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');

        return $names;
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $secret, string $tool, array $arguments): TestResponse
    {
        return $this->mcp($secret, 'tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? (object) [] : $arguments]);
    }

    /** @param array<string, mixed> $arguments */
    private function preview(string $secret, string $tool, array $arguments): string
    {
        $response = $this->tool($secret, $tool, $arguments);
        $this->assertSame(200, $response->getStatusCode(), $tool.' '.$response->getContent());
        $this->assertNotTrue($response->json('result.isError') ?? false, $response->getContent());

        return $this->structured($response)['preview_id'];
    }

    private function approve(User $user, string $previewId): void
    {
        $response = $this->actingAs($user, 'web')->postJson('/api/profile/mcp/actions/'.$previewId.'/approve');
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    private function complete(string $secret, string $previewId, bool $expectSuccess = true): TestResponse
    {
        $response = $this->tool($secret, 'complete_approved_mcp_change', [
            'preview_id' => $previewId, 'idempotency_key' => (string) Str::uuid(),
        ]);
        if ($expectSuccess) {
            $this->assertNotTrue($response->json('result.isError') ?? false, $response->getContent());
            $this->assertSame('completed', $this->structured($response->assertOk())['status'], $response->getContent());
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function structured(TestResponse $response): array
    {
        $payload = $response->json('result.structuredContent');
        if (! is_array($payload)) {
            $payload = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        }

        return $payload;
    }

    private function assertToolRejected(TestResponse $response): void
    {
        $this->assertTrue(
            $response->getStatusCode() !== 200 || $response->json('error') !== null || $response->json('result.isError') === true,
            'Expected the MCP call to be rejected: '.$response->getContent(),
        );
    }

    /** @param array<string, mixed> $params */
    private function mcp(string $secret, string $method, array $params = []): TestResponse
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
        $headers = [
            'Authorization' => 'Bearer '.$secret,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        $principal = $params['name'] ?? ($method === 'resources/read' ? $params['uri'] ?? null : null);
        if (is_string($principal)) {
            $headers['Mcp-Name'] = $principal;
        }

        return $this->withHeaders($headers)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => (string) Str::uuid(),
            'method' => $method,
            'params' => $params,
        ]);
    }
}
