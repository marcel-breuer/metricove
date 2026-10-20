<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinancialTransaction;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\TrackerDefinition;
use App\Models\TrackerEntry;
use App\Models\TrackerEntryValue;
use App\Models\TrackerField;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Models\Vehicle;
use App\Models\VehicleOdometerReading;
use App\Services\AI\Tools\TrackingSnapshotTool;
use App\Services\AI\Tools\UtilitiesSnapshotTool;
use App\Services\AI\Tools\VehiclesSnapshotTool;
use App\Services\Modules\ModuleManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.provider' => 'openai-compatible',
            'ai.base_url' => 'https://assistant.example/v1',
            'ai.model' => 'test-model',
            'ai.api_key' => '',
            'ai.external_provider' => false,
        ]);
    }

    public function test_finance_tool_returns_only_permission_visible_totals_and_does_not_store_chat(): void
    {
        $admin = $this->member(true);
        $viewer = $this->member(false);
        $owner = $this->member(false);
        $this->enable($admin, ['ai', 'finance']);
        $this->grant($viewer, 'ai', true);
        $this->grant($viewer, 'finance', true);

        $shared = $this->category($admin, false, 'Shared');
        $own = $this->category($viewer, true, 'Personal');
        $private = $this->category($owner, true, 'Hidden');
        $month = CarbonImmutable::today()->format('Y-m');
        $this->transaction($shared, $admin, false, '10.00', 'Shared note');
        $this->transaction($own, $viewer, true, '3.00', 'Personal note');
        $this->transaction($private, $owner, true, '900.00', 'Hidden private note');

        Http::fakeSequence()
            ->push($this->toolCall('summarize_finance_month', ['month' => $month]))
            ->push($this->assistantAnswer('Visible month totals only.'));

        $this->actingAs($viewer, 'web')->postJson('/api/ai/assistant', [
            'message' => 'Summarize expenses for this month.',
        ])->assertOk()->assertJsonPath('answer', 'Visible month totals only.');

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();
        $this->assertCount(2, $requests);
        $secondRequest = $requests[1]->data();
        $toolMessage = collect($secondRequest['messages'])->firstWhere('role', 'tool');
        $toolResult = json_decode($toolMessage['content'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('13.00', $toolResult['totals'][0]['expenses']);
        $this->assertStringNotContainsString('900.00', $toolMessage['content']);
        $this->assertStringNotContainsString('Hidden private note', $toolMessage['content']);
        $this->assertStringNotContainsString('Personal note', $toolMessage['content']);
        $this->assertFalse(Schema::hasTable('assistant_conversations'));
        $this->assertSame(3, FinancialTransaction::query()->count());
    }

    public function test_external_provider_requires_per_request_consent_before_any_network_call(): void
    {
        $admin = $this->member(true);
        $member = $this->member(false);
        $this->enable($admin, ['ai']);
        $this->grant($member, 'ai', true);
        config(['ai.external_provider' => true]);
        Http::fake(['*' => Http::response($this->assistantAnswer('Hello from the opted-in provider.'))]);

        $this->actingAs($member, 'web')->getJson('/api/ai/assistant')
            ->assertOk()
            ->assertJsonPath('requires_external_consent', true)
            ->assertJsonPath('provider', 'External OpenAI-compatible provider');

        $this->actingAs($member, 'web')->postJson('/api/ai/assistant', [
            'message' => 'Hello',
            'allow_external_data_sharing' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('allow_external_data_sharing');

        Http::assertNothingSent();

        $this->actingAs($member, 'web')->postJson('/api/ai/assistant', [
            'message' => 'Hello',
            'allow_external_data_sharing' => true,
        ])->assertOk()->assertJsonPath('answer', 'Hello from the opted-in provider.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://assistant.example/v1/chat/completions');
    }

    public function test_non_finance_tools_reuse_record_visibility_and_only_forward_bounded_fields(): void
    {
        $admin = $this->member(true);
        $viewer = $this->member(false);
        $owner = $this->member(false);
        $this->enable($admin, ['utilities', 'vehicles', 'tracking']);
        $this->grant($viewer, 'utilities', true);
        $this->grant($viewer, 'vehicles', true);
        $this->grant($viewer, 'tracking', true);

        $sharedMeter = UtilityMeter::query()->create([
            'owner_user_id' => null, 'created_by_user_id' => $admin->id, 'name' => 'Household meter',
            'meter_type' => 'electricity', 'unit' => 'kWh', 'is_private' => false,
        ]);
        $privateMeter = UtilityMeter::query()->create([
            'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id, 'name' => 'Secret meter',
            'meter_type' => 'water', 'unit' => 'm³', 'is_private' => true,
        ]);
        UtilityReading::query()->create([
            'utility_meter_id' => $sharedMeter->id, 'owner_user_id' => null, 'created_by_user_id' => $admin->id,
            'is_private' => false, 'reading_date' => CarbonImmutable::today()->format('Y-m-d'), 'value' => '123.000000',
            'note' => 'Do not send this note',
        ]);
        UtilityReading::query()->create([
            'utility_meter_id' => $privateMeter->id, 'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id,
            'is_private' => true, 'reading_date' => CarbonImmutable::today()->format('Y-m-d'), 'value' => '999.000000',
            'note' => 'Hidden meter note',
        ]);

        $sharedVehicle = Vehicle::query()->create([
            'owner_user_id' => null, 'created_by_user_id' => $admin->id, 'name' => 'Household car', 'make' => 'Example',
            'model' => 'One', 'fuel_type' => 'petrol', 'odometer_unit' => 'km', 'is_private' => false,
        ]);
        $privateVehicle = Vehicle::query()->create([
            'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id, 'name' => 'Secret car', 'make' => 'Example',
            'model' => 'Two', 'fuel_type' => 'petrol', 'odometer_unit' => 'km', 'is_private' => true,
        ]);
        VehicleOdometerReading::query()->create([
            'vehicle_id' => $sharedVehicle->id, 'owner_user_id' => null, 'created_by_user_id' => $admin->id,
            'is_private' => false, 'reading_date' => CarbonImmutable::today()->format('Y-m-d'), 'value' => '456.000',
            'note' => 'Do not send this vehicle note',
        ]);
        VehicleOdometerReading::query()->create([
            'vehicle_id' => $privateVehicle->id, 'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id,
            'is_private' => true, 'reading_date' => CarbonImmutable::today()->format('Y-m-d'), 'value' => '999.000',
            'note' => 'Hidden vehicle note',
        ]);

        $tracker = TrackerDefinition::query()->create([
            'created_by_user_id' => $admin->id, 'owner_user_id' => null, 'name' => 'Household tracker',
            'description' => 'Excluded description', 'is_private' => false, 'schema_version' => 1,
        ]);
        $numberField = TrackerField::query()->create([
            'tracker_definition_id' => $tracker->id, 'field_key' => 'number_field', 'label' => 'Count',
            'data_type' => 'number', 'unit' => 'items', 'sort_order' => 0, 'created_version' => 1,
        ]);
        $textField = TrackerField::query()->create([
            'tracker_definition_id' => $tracker->id, 'field_key' => 'text_field', 'label' => 'Private note field',
            'data_type' => 'text', 'unit' => null, 'sort_order' => 1, 'created_version' => 1,
        ]);
        $entry = TrackerEntry::query()->create([
            'tracker_definition_id' => $tracker->id, 'owner_user_id' => $viewer->id,
            'schema_version' => 1, 'recorded_at' => now(), 'note' => 'Do not send this tracker note',
        ]);
        TrackerEntryValue::query()->create([
            'tracker_entry_id' => $entry->id, 'tracker_field_id' => $numberField->id,
            'field_key_snapshot' => 'number_field', 'field_label_snapshot' => 'Count', 'data_type_snapshot' => 'number',
            'unit_snapshot' => 'items', 'decimal_value' => '7.000000',
        ]);
        TrackerEntryValue::query()->create([
            'tracker_entry_id' => $entry->id, 'tracker_field_id' => $textField->id,
            'field_key_snapshot' => 'text_field', 'field_label_snapshot' => 'Private note field', 'data_type_snapshot' => 'text',
            'text_value' => 'Private text value',
        ]);

        $utilities = app(UtilitiesSnapshotTool::class)->execute($viewer, []);
        $vehicles = app(VehiclesSnapshotTool::class)->execute($viewer, []);
        $tracking = app(TrackingSnapshotTool::class)->execute($viewer, []);
        $payload = json_encode([$utilities, $vehicles, $tracking], JSON_THROW_ON_ERROR);

        $this->assertCount(1, $utilities['meters']);
        $this->assertSame('Household meter', $utilities['meters'][0]['name']);
        $this->assertCount(1, $vehicles['vehicles']);
        $this->assertSame('Household car', $vehicles['vehicles'][0]['name']);
        $this->assertCount(1, $tracking['trackers']);
        $this->assertSame(1, count($tracking['trackers'][0]['entries'][0]['values']));
        $this->assertStringContainsString('123.000000', $payload);
        $this->assertStringContainsString('456.000', $payload);
        $this->assertStringContainsString('7.000000', $payload);
        $this->assertStringNotContainsString('Secret meter', $payload);
        $this->assertStringNotContainsString('Hidden meter note', $payload);
        $this->assertStringNotContainsString('Secret car', $payload);
        $this->assertStringNotContainsString('Hidden vehicle note', $payload);
        $this->assertStringNotContainsString('Do not send this tracker note', $payload);
        $this->assertStringNotContainsString('Private text value', $payload);
        $this->assertStringNotContainsString('Excluded description', $payload);
    }

    public function test_disabled_or_unpermitted_modules_are_not_offered_and_forced_calls_are_rejected(): void
    {
        $admin = $this->member(true);
        $member = $this->member(false);
        $this->enable($admin, ['ai', 'finance']);
        $this->grant($member, 'ai', true);
        $this->grant($member, 'finance', false);

        $this->actingAs($member, 'web')->getJson('/api/ai/assistant')
            ->assertOk()->assertJsonPath('modules', []);

        Http::fake(['*' => Http::response($this->toolCall('summarize_finance_month', ['month' => '2026-10']))]);
        $this->actingAs($member, 'web')->postJson('/api/ai/assistant', ['message' => 'Show finance totals'])
            ->assertUnprocessable()->assertJsonValidationErrors('assistant');
        $this->assertCount(1, Http::recorded());

        $this->grant($member, 'finance', true);
        app(ModuleManagementService::class)->setEnabled($admin, 'finance', false);
        $this->actingAs($member, 'web')->getJson('/api/ai/assistant')
            ->assertOk()->assertJsonPath('modules', []);

        $this->actingAs($member, 'web')->postJson('/api/ai/assistant', ['message' => 'Show finance totals'])
            ->assertUnprocessable()->assertJsonValidationErrors('assistant');
        $this->assertCount(2, Http::recorded());
    }

    public function test_unregistered_sql_or_shell_tool_calls_fail_closed(): void
    {
        $admin = $this->member(true);
        $member = $this->member(false);
        $this->enable($admin, ['ai']);
        $this->grant($member, 'ai', true);
        Http::fakeSequence()->push($this->toolCall('run_shell_command', ['command' => 'id']));

        $this->actingAs($member, 'web')->postJson('/api/ai/assistant', ['message' => 'Run an arbitrary command'])
            ->assertUnprocessable()->assertJsonValidationErrors('assistant');

        $this->assertCount(1, Http::recorded());
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_ai_module_access_and_provider_configuration_are_required(): void
    {
        $admin = $this->member(true);
        $member = $this->member(false);
        $this->enable($admin, ['ai']);
        $this->grant($member, 'ai', true);
        config(['ai.provider' => 'disabled']);

        $this->actingAs($member, 'web')->getJson('/api/ai/assistant')
            ->assertOk()->assertJsonPath('available', false)
            ->assertJsonPath('provider', 'No provider configured')
            ->assertJsonPath('external', false)
            ->assertJsonPath('requires_external_consent', false);
        $this->actingAs($member, 'web')->postJson('/api/ai/assistant', ['message' => 'Hello'])
            ->assertServiceUnavailable();

        app(ModuleManagementService::class)->setEnabled($admin, 'ai', false);
        $this->actingAs($member, 'web')->getJson('/api/ai/assistant')->assertNotFound();
    }

    private function member(bool $admin): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create(['is_admin' => $admin, 'is_active' => true]);
    }

    /** @param list<string> $modules */
    private function enable(User $admin, array $modules): void
    {
        foreach ($modules as $module) {
            app(ModuleManagementService::class)->setEnabled($admin, $module, true);
        }
    }

    private function grant(User $member, string $module, bool $view): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => $module],
            ['can_view' => $view, 'can_create' => false, 'can_edit' => false, 'can_manage' => false],
        );
    }

    private function category(User $owner, bool $private, string $name): FinanceCategory
    {
        return FinanceCategory::query()->create([
            'owner_user_id' => $private ? $owner->id : null,
            'created_by_user_id' => $owner->id,
            'is_private' => $private,
            'type' => 'expense',
            'name' => $name,
        ]);
    }

    private function transaction(FinanceCategory $category, User $owner, bool $private, string $amount, string $description): void
    {
        FinancialTransaction::query()->create([
            'finance_category_id' => $category->id,
            'owner_user_id' => $private ? $owner->id : null,
            'created_by_user_id' => $owner->id,
            'is_private' => $private,
            'type' => 'expense',
            'description' => $description,
            'amount' => $amount,
            'currency' => 'EUR',
            'occurred_on' => CarbonImmutable::today()->format('Y-m-d'),
        ]);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_test',
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)],
                    ]],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function assistantAnswer(string $answer): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $answer]]]];
    }
}
