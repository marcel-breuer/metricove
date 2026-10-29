<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Mail\UpcomingReminderMail;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurringEntry;
use App\Models\HouseholdReminder;
use App\Models\HouseholdSettings;
use App\Models\HouseholdTaskChecklistItem;
use App\Models\HouseholdTaskChecklistOccurrence;
use App\Models\PantryItem;
use App\Models\ResourceUserPermission;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehicleMaintenanceRecord;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ReminderModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_idempotent_permission_scoped_reminders_and_sends_only_opted_in_email(): void
    {
        [$admin, $owner, $viewer] = $this->members();
        $this->enableSources($admin);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 08:00:00'));
        $sources = $this->createUpcomingSources($owner);
        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $sources['pantry'], $viewer, ModuleCapability::View);
        Mail::fake();

        $reminders = app(ReminderService::class);
        $reminders->updatePreferences($owner, ['email_enabled' => true, 'lead_days' => 7]);
        $reminders->updatePreferences($viewer, ['lead_days' => 7]);

        $this->assertSame(['created' => 6, 'sent' => 5], $reminders->generate());
        $this->assertSame(['created' => 0, 'sent' => 0], $reminders->generate());
        Mail::assertSent(UpcomingReminderMail::class, 5);
        Mail::assertSent(UpcomingReminderMail::class, fn (UpcomingReminderMail $mail): bool => $mail->hasTo($owner->email));
        Mail::assertNotSent(UpcomingReminderMail::class, fn (UpcomingReminderMail $mail): bool => $mail->hasTo($viewer->email));

        $this->actingAs($owner, 'web')->getJson('/api/reminders')->assertOk()
            ->assertJsonCount(5, 'reminders')->assertJsonPath('unread_count', 5);
        $this->actingAs($viewer, 'web')->getJson('/api/reminders')->assertOk()
            ->assertJsonCount(1, 'reminders')->assertJsonPath('reminders.0.source_module', 'pantry')
            ->assertJsonPath('reminders.0.title', 'Best before · Oat milk');
        $this->actingAs($admin, 'web')->getJson('/api/reminders')->assertOk()->assertJsonCount(0, 'reminders');

        $viewerReminder = HouseholdReminder::query()->where('recipient_user_id', $viewer->id)->firstOrFail();
        $this->actingAs($viewer, 'web')->patchJson('/api/reminders/'.$viewerReminder->id, ['action' => 'read'])
            ->assertOk();
        $this->actingAs($viewer, 'web')->getJson('/api/reminders')->assertOk()
            ->assertJsonPath('reminders.0.is_read', true)->assertJsonPath('unread_count', 0);

        $accessRow = ResourceUserPermission::query()
            ->where('resource_type', $sources['pantry']->getMorphClass())
            ->where('resource_id', $sources['pantry']->id)
            ->where('user_id', $viewer->id)
            ->where('capability', 'view')
            ->firstOrFail();
        $accessRow->delete();
        $this->actingAs($viewer, 'web')->getJson('/api/reminders')->assertOk()->assertJsonCount(0, 'reminders');
        $this->assertDatabaseMissing('household_reminders', ['id' => $viewerReminder->id]);
    }

    public function test_member_can_disable_a_source_and_source_module_disable_removes_its_reminders(): void
    {
        [$admin, $owner] = $this->members();
        $this->enableSources($admin);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 08:00:00'));
        $this->createUpcomingSources($owner);
        $service = app(ReminderService::class);
        $service->updatePreferences($owner, ['sources' => ['pantry' => false], 'lead_days' => 7]);

        $service->generate();
        $this->actingAs($owner, 'web')->getJson('/api/reminders')->assertOk()
            ->assertJsonCount(4, 'reminders')->assertJsonMissing(['source_module' => 'pantry']);

        app(ModuleManagementService::class)->setEnabled($admin, 'finance', false);
        $this->actingAs($owner, 'web')->getJson('/api/reminders')->assertOk()
            ->assertJsonCount(3, 'reminders')->assertJsonMissing(['source_module' => 'finance']);
        $this->assertDatabaseMissing('household_reminders', ['recipient_user_id' => $owner->id, 'source_module' => 'finance']);
    }

    public function test_ambiguous_email_success_is_not_retried_when_delivery_marker_fails(): void
    {
        [$admin, $owner] = $this->members();
        $this->enableSources($admin);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 08:00:00'));
        $this->createUpcomingSources($owner);
        $service = app(ReminderService::class);
        $service->updatePreferences($owner, [
            'email_enabled' => true,
            'lead_days' => 7,
            'sources' => ['vehicles' => false, 'utilities' => false, 'pantry' => false],
        ]);
        Mail::fake();

        $failAfterSend = true;
        HouseholdReminder::saving(function (HouseholdReminder $reminder) use (&$failAfterSend): void {
            if ($failAfterSend && $reminder->emailed_at !== null) {
                $failAfterSend = false;
                throw new RuntimeException('Simulated failure after SMTP accepted the message.');
            }
        });

        try {
            $service->generate();
            $this->fail('The simulated delivery-marker failure should escape the scheduler run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after SMTP accepted the message.', $exception->getMessage());
        }

        Mail::assertSent(UpcomingReminderMail::class, 1);
        $reminder = HouseholdReminder::query()->where('recipient_user_id', $owner->id)->firstOrFail();
        $this->assertNotNull($reminder->email_claimed_at);
        $this->assertNull($reminder->emailed_at);

        $this->assertSame(['created' => 0, 'sent' => 0], $service->generate());
        Mail::assertSent(UpcomingReminderMail::class, 1);
    }

    public function test_pending_reminder_follows_email_opt_in_changed_after_creation(): void
    {
        [$admin, $owner] = $this->members();
        $this->enableSources($admin);
        $this->travelTo(CarbonImmutable::parse('2026-10-24 08:00:00'));
        $this->createUpcomingSources($owner);
        $service = app(ReminderService::class);
        $service->updatePreferences($owner, [
            'lead_days' => 7,
            'sources' => ['vehicles' => false, 'utilities' => false, 'pantry' => false],
        ]);
        Mail::fake();

        $this->assertSame(['created' => 1, 'sent' => 0], $service->generate());
        $reminder = HouseholdReminder::query()->where('recipient_user_id', $owner->id)->firstOrFail();
        $this->assertFalse($reminder->email_requested);
        Mail::assertNothingSent();

        $service->updatePreferences($owner, ['email_enabled' => true]);

        $this->assertSame(['created' => 0, 'sent' => 1], $service->generate());
        $this->assertSame(['created' => 0, 'sent' => 0], $service->generate());
        Mail::assertSent(UpcomingReminderMail::class, 1);
        $this->assertNotNull($reminder->fresh()->emailed_at);
    }

    public function test_preferences_are_member_scoped_and_validated(): void
    {
        [$admin, $owner, $other] = $this->members();
        $this->enableSources($admin);

        $this->actingAs($owner, 'web')->getJson('/api/reminders/preferences')->assertOk()
            ->assertJsonPath('preferences.email_enabled', false)
            ->assertJsonPath('preferences.lead_days', 3)
            ->assertJsonPath('preferences.sources.finance', true);
        $this->actingAs($owner, 'web')->patchJson('/api/reminders/preferences', [
            'email_enabled' => true,
            'lead_days' => 10,
            'sources' => ['finance' => false],
        ])->assertOk()->assertJsonPath('preferences.email_enabled', true)
            ->assertJsonPath('preferences.lead_days', 10)
            ->assertJsonPath('preferences.sources.finance', false)
            ->assertJsonPath('preferences.sources.pantry', true);
        $this->actingAs($owner, 'web')->patchJson('/api/reminders/preferences', ['lead_days' => 31])
            ->assertUnprocessable()->assertJsonValidationErrors('lead_days');
        $this->actingAs($other, 'web')->getJson('/api/reminders/preferences')->assertOk()
            ->assertJsonPath('preferences.email_enabled', false)
            ->assertJsonPath('preferences.lead_days', 3)
            ->assertJsonPath('preferences.sources.finance', true);
    }

    public function test_task_checklist_reminders_use_local_due_time_optional_lead_and_daily_repeats_until_completed(): void
    {
        [$admin, $owner] = $this->members();
        $this->enableSources($admin);
        $this->travelTo(CarbonImmutable::parse('2026-10-29T07:45:00Z'));
        $taskId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Change tire', 'visibility' => 'household', 'assignee_id' => $owner->id,
            'priority' => 'medium', 'timezone' => 'Europe/Berlin', 'due_at' => '2026-10-29T08:00:00Z',
        ])->assertCreated()->json('task.id');
        $item = $this->actingAs($admin, 'web')->postJson("/api/tasks/{$taskId}/checklist-items", [
            'title' => 'Check pressure', 'due_at' => '2026-10-29T10:00:00+02:00',
            'reminder_lead_minutes' => 15, 'expected_version' => 1,
        ])->assertCreated();
        $itemId = (int) $item->json('task.checklist_items.0.id');
        $occurrenceId = HouseholdTaskChecklistOccurrence::query()->where('checklist_item_id', $itemId)->value('id');
        $this->assertNotNull($occurrenceId);

        $service = app(ReminderService::class);
        $service->updatePreferences($owner, ['email_enabled' => true]);
        Mail::fake();
        $this->assertSame(['created' => 1, 'sent' => 1], $service->generateTaskReminders());
        $this->assertSame(['created' => 0, 'sent' => 0], $service->generateTaskReminders());
        $this->actingAs($owner, 'web')->getJson('/api/reminders')->assertOk()
            ->assertJsonCount(1, 'reminders')->assertJsonPath('reminders.0.source_module', 'tasks')
            ->assertJsonPath('reminders.0.title', 'Task · Change tire · Check pressure is due')
            ->assertJsonPath('reminders.0.due_on', '2026-10-29')
            ->assertJsonPath('reminders.0.href', "/modules/tasks#task-{$taskId}-checklist-item-{$itemId}");
        Mail::assertSent(UpcomingReminderMail::class, 1);

        $this->travelTo(CarbonImmutable::parse('2026-10-29T08:00:00Z'));
        $this->assertSame(['created' => 1, 'sent' => 1], $service->generateTaskReminders());
        $this->travelTo(CarbonImmutable::parse('2026-10-30T08:01:00Z'));
        $this->assertSame(['created' => 1, 'sent' => 1], $service->generateTaskReminders());
        $this->assertSame(['created' => 0, 'sent' => 0], $service->generateTaskReminders());
        Mail::assertSent(UpcomingReminderMail::class, 3);
        $this->assertDatabaseCount('household_reminders', 3);

        $this->actingAs($owner, 'web')->patchJson("/api/tasks/{$taskId}/checklist-items/{$itemId}/completion", [
            'completed' => true, 'expected_version' => 2,
        ])->assertOk()->assertJsonPath('task.is_completed', true);
        $this->travelTo(CarbonImmutable::parse('2026-10-31T08:01:00Z'));
        $this->assertSame(['created' => 0, 'sent' => 0], $service->generateTaskReminders());
        $this->assertSame(3, HouseholdReminder::query()->count());
        $this->assertSame(0, HouseholdTaskChecklistItem::query()->whereKey($itemId)->where('reminder_lead_minutes', 0)->count());
    }

    /** @return list<User> */
    private function members(): array
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return [
            User::factory()->create(['name' => 'Reminder Admin', 'email' => 'reminders-admin@example.test', 'is_admin' => true, 'is_active' => true]),
            User::factory()->create(['name' => 'Reminder Owner', 'email' => 'reminders-owner@example.test', 'is_admin' => false, 'is_active' => true]),
            User::factory()->create(['name' => 'Reminder Viewer', 'email' => 'reminders-viewer@example.test', 'is_admin' => false, 'is_active' => true]),
        ];
    }

    private function enableSources(User $admin): void
    {
        foreach (['finance', 'vehicles', 'utilities', 'pantry', 'tasks'] as $module) {
            app(ModuleManagementService::class)->setEnabled($admin, $module, true);
        }
    }

    /** @return array{pantry: PantryItem} */
    private function createUpcomingSources(User $owner): array
    {
        $category = FinanceCategory::query()->create([
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'is_private' => true,
            'type' => 'expense',
            'name' => 'Bills',
        ]);
        FinanceRecurringEntry::query()->create([
            'finance_category_id' => $category->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'is_private' => true,
            'type' => 'expense',
            'description' => 'Electricity bill',
            'amount' => '80.00',
            'currency' => 'EUR',
            'frequency' => 'monthly',
            'starts_on' => '2026-01-01',
            'next_due_on' => '2026-10-26',
            'ends_on' => null,
            'is_active' => true,
        ]);

        $vehicle = Vehicle::query()->create([
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Private car',
            'make' => 'Example',
            'model' => 'One',
            'model_year' => 2022,
            'fuel_type' => 'petrol',
            'odometer_unit' => 'km',
            'description' => null,
            'is_private' => true,
        ]);
        VehicleInsurancePolicy::query()->create([
            'vehicle_id' => $vehicle->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'is_private' => true,
            'provider' => 'Private insurer',
            'coverage_type' => 'liability',
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-10-27',
            'note' => null,
        ]);
        VehicleMaintenanceRecord::query()->create([
            'vehicle_id' => $vehicle->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'is_private' => true,
            'title' => 'Annual service',
            'service_type' => 'service',
            'occurred_on' => '2026-01-10',
            'next_due_on' => '2026-10-28',
            'odometer_value' => null,
            'note' => null,
        ]);

        $meter = UtilityMeter::query()->create([
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Private meter',
            'meter_type' => 'electricity',
            'unit' => 'kWh',
            'description' => null,
            'is_private' => true,
            'alert_threshold' => '5.000000',
        ]);
        $meter->readings()->createMany([
            ['owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id, 'is_private' => true, 'reading_date' => '2026-10-01', 'value' => '100.000000'],
            ['owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id, 'is_private' => true, 'reading_date' => '2026-10-20', 'value' => '120.000000'],
        ]);

        $pantry = PantryItem::query()->create([
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'is_private' => true,
            'name' => 'Oat milk',
            'quantity' => '1.000',
            'unit' => 'carton',
            'location' => 'Fridge',
            'best_before_on' => '2026-10-29',
            'version' => 1,
        ]);

        return ['pantry' => $pantry];
    }
}
