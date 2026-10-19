<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\TrackerDefinition;
use App\Models\TrackerEntry;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrackingModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracker_schema_revisions_keep_decimal_measurement_history_compatible(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($member, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $tracker = $this->actingAs($admin, 'web')->postJson('/api/tracking/trackers', [
            'name' => 'Body measurements',
            'description' => 'A shared measurement template.',
            'visibility' => 'household',
            'fields' => [
                [
                    'label' => 'Weight',
                    'type' => 'number',
                    'unit' => 'kg',
                    'is_required' => true,
                    'minimum' => '0.000001',
                    'maximum' => '999.999999',
                ],
                [
                    'label' => 'Context',
                    'type' => 'text',
                    'unit' => null,
                    'is_required' => false,
                    'minimum' => null,
                    'maximum' => null,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('tracker.visibility', 'household')
            ->assertJsonPath('tracker.schema_version', 1);

        $trackerId = (int) $tracker->json('tracker.id');
        $weight = $tracker->json('tracker.fields.0');
        $context = $tracker->json('tracker.fields.1');

        $entry = $this->actingAs($member, 'web')->postJson('/api/tracking/trackers/'.$trackerId.'/entries', [
            'schema_version' => 1,
            'recorded_at' => '2026-10-19T10:15:00+02:00',
            'note' => 'Morning reading',
            'values' => [
                $weight['key'] => '72.300001',
                $context['key'] => 'Before breakfast',
            ],
        ])->assertCreated()
            ->assertJsonPath('entry.schema_version', 1)
            ->assertJsonPath('entry.values.0.unit', 'kg');

        $entryId = (int) $entry->json('entry.id');
        $this->assertContains(Schema::getColumnType('tracker_entry_values', 'decimal_value'), ['decimal', 'numeric']);
        $this->assertDatabaseHas('tracker_entry_values', [
            'tracker_entry_id' => $entryId,
            'tracker_field_id' => $weight['id'],
            'decimal_value' => '72.300001',
            'unit_snapshot' => 'kg',
        ]);

        $changed = $this->actingAs($admin, 'web')->putJson('/api/tracking/trackers/'.$trackerId, [
            'schema_version' => 1,
            'name' => 'Body measurements',
            'description' => 'A revised measurement template.',
            'fields' => [
                [
                    'id' => $weight['id'],
                    'label' => 'Weight',
                    'type' => 'number',
                    'unit' => 'kg',
                    'is_required' => true,
                    'minimum' => '0.000001',
                    'maximum' => '999.999999',
                ],
                [
                    'label' => 'Resting pulse',
                    'type' => 'number',
                    'unit' => 'bpm',
                    'is_required' => true,
                    'minimum' => '20',
                    'maximum' => '250',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('tracker.schema_version', 2)
            ->assertJsonCount(2, 'tracker.fields');

        $pulse = $changed->json('tracker.fields.1');
        $this->assertDatabaseHas('tracker_fields', [
            'id' => $context['id'],
            'retired_version' => 2,
        ]);
        $this->assertDatabaseCount('tracker_schema_revisions', 2);

        $detail = $this->actingAs($member, 'web')->getJson('/api/tracking/trackers/'.$trackerId)
            ->assertOk()
            ->assertJsonPath('schema_revisions.0.version', 2)
            ->assertJsonPath('schema_revisions.1.version', 1);
        $this->assertSame($context['key'], $detail->json('schema_revisions.1.schema.fields.1.key'));

        $this->actingAs($admin, 'web')->putJson('/api/tracking/trackers/'.$trackerId, [
            'schema_version' => 2,
            'name' => 'Body measurements',
            'description' => 'A revised measurement template.',
            'fields' => [
                [
                    'id' => $weight['id'],
                    'label' => 'Weight',
                    'type' => 'number',
                    'unit' => 'lb',
                    'is_required' => true,
                    'minimum' => '0.000001',
                    'maximum' => '999.999999',
                ],
                [
                    'id' => $pulse['id'],
                    'label' => 'Resting pulse',
                    'type' => 'number',
                    'unit' => 'bpm',
                    'is_required' => true,
                    'minimum' => '20',
                    'maximum' => '250',
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('fields.0.type');
        $this->assertSame(2, TrackerDefinition::query()->findOrFail($trackerId)->schema_version);

        $this->actingAs($member, 'web')->postJson('/api/tracking/trackers/'.$trackerId.'/entries', [
            'schema_version' => 1,
            'recorded_at' => '2026-10-19T10:15:00+02:00',
            'values' => [$weight['key'] => '72.3'],
        ])->assertStatus(409);

        $edited = $this->actingAs($member, 'web')->patchJson('/api/tracking/entries/'.$entryId, [
            'schema_version' => 2,
            'recorded_at' => '2026-10-19T10:20:00+02:00',
            'note' => 'Corrected reading',
            'values' => [
                $weight['key'] => '72.400000',
                $pulse['key'] => '56',
            ],
        ])->assertOk()
            ->assertJsonPath('entry.schema_version', 2);

        $savedValues = collect($edited->json('entry.values'));
        $this->assertSame('Before breakfast', $savedValues->firstWhere('key', $context['key'])['value']);
        $this->assertSame('kg', $savedValues->firstWhere('key', $weight['key'])['unit']);
        $this->assertDatabaseHas('tracker_entry_values', [
            'tracker_entry_id' => $entryId,
            'field_key_snapshot' => $context['key'],
            'text_value' => 'Before breakfast',
        ]);

        $this->actingAs($member, 'web')->postJson('/api/tracking/trackers/'.$trackerId.'/entries', [
            'schema_version' => 2,
            'recorded_at' => '2026-10-20T08:00:00+02:00',
            'values' => [
                $weight['key'] => '73.125',
                $pulse['key'] => '61',
            ],
        ])->assertCreated()
            ->assertJsonPath('entry.values.0.unit', 'kg');

        $this->actingAs($admin, 'web')->getJson('/api/tracking/trackers/'.$trackerId.'/entries')
            ->assertOk()
            ->assertJsonCount(0, 'entries');
        $history = $this->actingAs($member, 'web')->getJson('/api/tracking/trackers/'.$trackerId.'/entries')
            ->assertOk();
        $this->assertCount(2, $history->json('entries'));
        $this->assertSame('73.125000', $history->json('entries.0.values.0.value'));
    }

    public function test_private_trackers_require_exact_member_and_resource_permissions(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true, 'edit' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $private = $this->actingAs($owner, 'web')->postJson('/api/tracking/trackers', [
            'name' => 'Private steps',
            'description' => null,
            'visibility' => 'private',
            'fields' => [
                [
                    'label' => 'Steps',
                    'type' => 'number',
                    'unit' => 'steps',
                    'is_required' => true,
                    'minimum' => '0',
                    'maximum' => '100000',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('tracker.visibility', 'private');

        $trackerId = (int) $private->json('tracker.id');
        $field = $private->json('tracker.fields.0');
        $entry = $this->actingAs($owner, 'web')->postJson('/api/tracking/trackers/'.$trackerId.'/entries', [
            'schema_version' => 1,
            'recorded_at' => '2026-10-19T12:00:00Z',
            'values' => [$field['key'] => '8000'],
        ])->assertCreated();
        $entryId = (int) $entry->json('entry.id');

        $this->actingAs($admin, 'web')->getJson('/api/tracking/trackers/'.$trackerId)->assertNotFound();
        $this->actingAs($viewer, 'web')->getJson('/api/tracking/trackers/'.$trackerId)->assertNotFound();
        $this->actingAs($admin, 'web')->getJson('/api/tracking/trackers/'.$trackerId.'/entries')->assertNotFound();

        $access = app(ModuleAccessService::class);
        $tracker = TrackerDefinition::query()->findOrFail($trackerId);
        $entryModel = TrackerEntry::query()->findOrFail($entryId);
        $access->grantPrivateResourceAccess($owner, $tracker, $admin, ModuleCapability::View);
        $access->grantPrivateResourceAccess($owner, $entryModel, $admin, ModuleCapability::View);

        $this->actingAs($admin, 'web')->getJson('/api/tracking/trackers/'.$trackerId)
            ->assertOk()
            ->assertJsonPath('tracker.name', 'Private steps');
        $this->actingAs($admin, 'web')->getJson('/api/tracking/trackers/'.$trackerId.'/entries')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.can_edit', false);

        $this->actingAs($viewer, 'web')->getJson('/api/tracking/trackers/'.$trackerId)->assertNotFound();
        $this->actingAs($viewer, 'web')->postJson('/api/tracking/trackers/'.$trackerId.'/entries', [
            'schema_version' => 1,
            'recorded_at' => '2026-10-19T12:30:00Z',
            'values' => [$field['key'] => '8001'],
        ])->assertForbidden();

        $this->actingAs($owner, 'web')->postJson('/api/tracking/trackers', [
            'name' => 'Shared by a non-admin',
            'visibility' => 'household',
            'fields' => [
                [
                    'label' => 'Reading',
                    'type' => 'number',
                    'unit' => 'count',
                    'is_required' => true,
                    'minimum' => null,
                    'maximum' => null,
                ],
            ],
        ])->assertForbidden();

        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', false);
        $this->actingAs($owner, 'web')->getJson('/api/tracking/trackers')->assertNotFound();
    }

    public function test_numeric_field_validation_requires_explicit_units_and_exact_decimal_strings(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $this->actingAs($admin, 'web')->postJson('/api/tracking/trackers', [
            'name' => 'Invalid unit',
            'visibility' => 'private',
            'fields' => [
                [
                    'label' => 'Temperature',
                    'type' => 'number',
                    'unit' => '',
                    'is_required' => true,
                    'minimum' => null,
                    'maximum' => null,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('fields.0.unit');
        $this->assertDatabaseCount('tracker_definitions', 0);

        $tracker = $this->actingAs($admin, 'web')->postJson('/api/tracking/trackers', [
            'name' => 'Temperature',
            'visibility' => 'private',
            'fields' => [
                [
                    'label' => 'Temperature',
                    'type' => 'number',
                    'unit' => '°C',
                    'is_required' => true,
                    'minimum' => '-10.5',
                    'maximum' => '50.25',
                ],
            ],
        ])->assertCreated();

        $trackerId = (int) $tracker->json('tracker.id');
        $key = $tracker->json('tracker.fields.0.key');
        $this->actingAs($admin, 'web')->postJson('/api/tracking/trackers/'.$trackerId.'/entries', [
            'schema_version' => 1,
            'recorded_at' => '2026-10-19T12:00:00Z',
            'values' => [$key => '50.250001'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('values.'.$key);
        $this->actingAs($admin, 'web')->postJson('/api/tracking/trackers/'.$trackerId.'/entries', [
            'schema_version' => 1,
            'recorded_at' => '2026-10-19T12:00:00Z',
            'values' => [$key => 0.1],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('values.'.$key);
        $this->assertDatabaseCount('tracker_entries', 0);
        $this->assertContains(Schema::getColumnType('tracker_entry_values', 'decimal_value'), ['decimal', 'numeric']);
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create([
            'name' => 'Household Admin',
            'email' => 'tracking-admin@example.test',
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'tracking'],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }
}
