<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_installation_reports_setup_required(): void
    {
        $this->getJson('/api/setup/status')
            ->assertOk()
            ->assertJsonPath('setup_required', true);

        $this->assertDatabaseCount('household_settings', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_rejects_a_second_household_settings_row(): void
    {
        $this->expectException(QueryException::class);

        DB::table('household_settings')->insert([
            'id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_first_registration_creates_the_active_admin_and_initializes_a_session(): void
    {
        $payload = $this->registrationPayload();
        $payload['is_admin'] = false;
        $payload['is_active'] = false;

        $response = $this->postJson('/api/setup', $payload)
            ->assertCreated()
            ->assertJsonPath('user.name', 'Metricove Owner')
            ->assertJsonPath('user.email', 'owner@example.test')
            ->assertJsonPath('user.is_admin', true)
            ->assertJsonPath('user.is_active', true)
            ->assertJsonMissingPath('user.password');

        $user = User::query()->sole();
        $settings = HouseholdSettings::query()->sole();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($payload['password'], $user->password);
        $this->assertNotNull($settings->setup_completed_at);
        $this->assertSame(1, User::query()->where('is_admin', true)->where('is_active', true)->count());
        $this->assertFalse($response->isServerError());
    }

    public function test_registration_is_closed_after_the_first_account_is_created(): void
    {
        $this->postJson('/api/setup', $this->registrationPayload())->assertCreated();

        $this->postJson('/api/setup', $this->registrationPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'Initial setup has already been completed.');

        $this->getJson('/api/setup/status')
            ->assertOk()
            ->assertJsonPath('setup_required', false);

        $this->assertDatabaseCount('household_settings', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_registration_does_not_claim_setup(): void
    {
        $payload = $this->registrationPayload();
        $payload['password'] = 'short';
        $payload['password_confirmation'] = 'short';

        $this->postJson('/api/setup', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->getJson('/api/setup/status')
            ->assertOk()
            ->assertJsonPath('setup_required', true);

        $this->assertDatabaseCount('users', 0);
        $this->assertNull(HouseholdSettings::query()->sole()->setup_completed_at);
    }

    /**
     * @return array{name: string, email: string, password: string, password_confirmation: string}
     */
    private function registrationPayload(): array
    {
        $password = Str::random(32);

        return [
            'name' => '  Metricove Owner  ',
            'email' => ' OWNER@example.test ',
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }
}
