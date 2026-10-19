<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\PasswordResetService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_request_is_generic_for_existing_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);

        $known = $this->postJson('/api/auth/password/email', [
            'email' => ' MEMBER@Example.Test ',
        ])->assertAccepted();

        $unknown = $this->postJson('/api/auth/password/email', [
            'email' => 'unknown@example.test',
        ])->assertAccepted();

        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame(
            'If an account exists for that address, password reset instructions have been sent.',
            $known->json('message'),
        );
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_token_is_stored_as_a_hash_and_email_link_uses_frontend_route(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->postJson('/api/auth/password/email', ['email' => $user->email])->assertAccepted();

        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $token = $notification->token;
        $storedHash = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $actionUrl = $notification->toMail($user)->actionUrl;
        $actionPath = parse_url($actionUrl, PHP_URL_PATH);
        $actionQuery = parse_url($actionUrl, PHP_URL_QUERY);
        $actionFragment = parse_url($actionUrl, PHP_URL_FRAGMENT);

        $this->assertIsString($storedHash);
        $this->assertNotSame($token, $storedHash);
        $this->assertTrue(Hash::check($token, $storedHash));
        $this->assertSame('/password/reset', $actionPath);
        $this->assertNull($actionQuery);
        $this->assertSame('token='.$token, $actionFragment);
    }

    public function test_reset_request_is_rate_limited_by_ip(): void
    {
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/auth/password/email', ['email' => 'unknown@example.test'])->assertAccepted();
        }

        $this->postJson('/api/auth/password/email', ['email' => 'unknown@example.test'])
            ->assertTooManyRequests();
    }

    public function test_reset_submission_is_rate_limited_by_ip(): void
    {
        $password = Str::random(32);
        $payload = [
            'email' => 'unknown@example.test',
            'token' => Str::random(64),
            'password' => $password,
            'password_confirmation' => $password,
        ];

        foreach (range(1, 10) as $_) {
            $this->postJson('/api/auth/password/reset', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/auth/password/reset', $payload)->assertTooManyRequests();
    }

    public function test_smtp_failure_keeps_response_generic_and_logs_no_recipient_or_reset_token(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $transportMessage = Str::random(32);

        $resetService = \Mockery::mock(PasswordResetService::class);
        $resetService->shouldReceive('send')
            ->once()
            ->with($user->email)
            ->andThrow(new TransportException($transportMessage));
        $this->app->instance(PasswordResetService::class, $resetService);

        Log::shouldReceive('warning')
            ->once()
            ->with('Password reset email delivery failed.', [
                'exception_type' => TransportException::class,
            ]);

        $this->postJson('/api/auth/password/email', ['email' => $user->email])
            ->assertAccepted()
            ->assertJsonPath(
                'message',
                'If an account exists for that address, password reset instructions have been sent.',
            );
    }

    public function test_password_broker_throttles_repeat_token_requests_for_the_same_member(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->postJson('/api/auth/password/email', ['email' => $user->email])->assertAccepted();
        $this->postJson('/api/auth/password/email', ['email' => $user->email])->assertAccepted();

        $this->assertCount(1, Notification::sent($user, ResetPassword::class));
    }

    public function test_valid_reset_changes_password_and_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $token = Password::broker()->createToken($user);
        $newPassword = Str::random(32);
        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ];

        $this->postJson('/api/auth/password/reset', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset. You can sign in now.');

        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->postJson('/api/auth/password/reset', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This password reset link is invalid or has expired.');
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $token = Password::broker()->createToken($user);
        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();
        $newPassword = Str::random(32);

        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This password reset link is invalid or has expired.');

        $this->assertFalse(Hash::check($newPassword, $user->fresh()->password));
    }
}
