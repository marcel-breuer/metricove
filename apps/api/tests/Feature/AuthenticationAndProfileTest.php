<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class AuthenticationAndProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_normalizes_email_and_exposes_only_public_user_fields(): void
    {
        $password = Str::random(32);
        $user = User::factory()->create([
            'name' => 'Household Member',
            'email' => 'member@example.test',
            'password' => $password,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => '  MEMBER@Example.Test ',
            'password' => $password,
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'member@example.test')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotEmpty($response->getCookie(config('session.cookie'))?->getValue());
    }

    public function test_inactive_members_and_invalid_credentials_cannot_sign_in(): void
    {
        $password = Str::random(32);
        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => $password,
            'is_active' => false,
        ]);

        $inactive = $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.test',
            'password' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are incorrect.');

        $unknown = $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.test',
            'password' => Str::random(32),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are incorrect.');

        $this->assertSame($inactive->json('message'), $unknown->json('message'));
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_by_ip_and_normalized_email(): void
    {
        $payload = [
            'email' => 'member@example.test',
            'password' => Str::random(32),
        ];

        foreach (range(1, 5) as $attempt) {
            $payload['email'] = $attempt % 2 === 0
                ? ' MEMBER@Example.Test '
                : 'MEMBER@example.test';

            $this->postJson('/api/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', $payload)->assertTooManyRequests();
    }

    public function test_authenticated_member_can_sign_out_and_session_endpoint_returns_null(): void
    {
        $password = Str::random(32);
        $user = User::factory()->create([
            'email' => 'member@example.test',
            'password' => $password,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        $this->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Signed out.');

        $this->assertGuest('web');
        $this->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('user', null);
    }

    public function test_session_cookie_uses_secure_http_only_and_same_site_flags(): void
    {
        config([
            'session.cookie' => 'metricove-session-test',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $response = $this->getJson('/api/auth/session')->assertOk();
        $cookie = $response->getCookie('metricove-session-test');

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_public_registration_endpoint_does_not_exist_after_setup(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Unexpected Registration',
            'email' => 'new@example.test',
            'password' => Str::random(32),
        ])->assertNotFound();
    }

    public function test_email_is_canonicalized_and_database_rejects_normalized_duplicates(): void
    {
        $first = User::factory()->create(['email' => ' MEMBER@Example.Test ']);

        $this->assertSame('member@example.test', $first->email);

        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'name' => 'Second Member',
            'email' => ' MEMBER@example.test ',
            'password' => Hash::make(Str::random(32)),
            'is_admin' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_member_can_update_profile_but_cannot_change_privileged_fields(): void
    {
        $password = Str::random(32);
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@example.test',
            'password' => $password,
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/profile', [
                'name' => '  Updated Name  ',
                'email' => '  UPDATED@Example.Test ',
                'is_admin' => false,
                'is_active' => false,
                'password' => Str::random(32),
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Name')
            ->assertJsonPath('user.email', 'updated@example.test')
            ->assertJsonPath('user.is_admin', true);

        $user->refresh();
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_profile_rejects_email_already_used_with_different_case_or_whitespace(): void
    {
        $current = User::factory()->create(['email' => 'current@example.test']);
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($current, 'web')
            ->patchJson('/api/profile', [
                'name' => $current->name,
                'email' => '  TAKEN@Example.Test ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
