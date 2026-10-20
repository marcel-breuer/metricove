<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\HouseholdSettings;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_send_email_only_invitation_and_view_pending_record(): void
    {
        Mail::fake();
        config(['app.url' => 'https://metricove.example/household/']);
        $admin = $this->admin();

        $this->withServerVariables(['HTTP_HOST' => 'attacker.example'])
            ->actingAs($admin, 'web')
            ->postJson('/api/admin/invitations', [
                'email' => '  MEMBER@Example.Test ',
            ])
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'member@example.test');

        $invitation = Invitation::query()->sole();
        $token = $this->sentInvitationToken();

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'email' => 'member@example.test',
            'invited_by' => $admin->id,
            'token_hash' => hash('sha256', $token),
        ]);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertDatabaseCount('users', 1);

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($token, $invitation): bool {
            preg_match('/href="([^"]+)"/', $mail->render(), $matches);
            $url = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5);
            $parts = parse_url($url);
            $origin = ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '');

            $this->assertSame('https://metricove.example', $origin);
            $this->assertSame('/household/invitations/accept', $parts['path'] ?? null);
            $this->assertArrayNotHasKey('query', $parts);
            $this->assertSame('token='.$token, $parts['fragment'] ?? null);
            $this->assertStringContainsString($invitation->expires_at->toDayDateTimeString(), $mail->render());

            return true;
        });

        $this->getJson('/api/admin/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.email', 'member@example.test')
            ->assertJsonMissingPath('invitations.0.token_hash');
    }

    public function test_only_active_administrators_can_manage_invitations(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $inactiveAdmin = User::factory()->create(['is_admin' => true, 'is_active' => false]);

        $this->getJson('/api/admin/invitations')->assertUnauthorized();

        $this->actingAs($member, 'web')
            ->postJson('/api/admin/invitations', ['email' => 'person@example.test'])
            ->assertForbidden();

        $this->actingAs($inactiveAdmin, 'web')
            ->getJson('/api/admin/invitations')
            ->assertUnauthorized();

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_inviter_cannot_supply_identity_or_password_fields(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->postJson('/api/admin/invitations', [
                'email' => 'member@example.test',
                'name' => 'Admin Selected Name',
                'password' => Str::random(32),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'password']);

        $this->assertDatabaseCount('invitations', 0);
        $this->assertDatabaseCount('users', 1);
        Mail::assertNothingSent();
    }

    public function test_recipient_claims_invitation_with_own_name_and_password_and_verified_email(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->sendInvitation($admin, 'recipient@example.test');
        $token = $this->sentInvitationToken();
        $password = Str::random(32);

        $this->postJson('/api/invitations/claim', [
            'token' => $token,
            'name' => '  Recipient Chosen Name  ',
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Your account is ready. You are signed in.');

        $member = User::query()->where('email', 'recipient@example.test')->sole();
        $this->assertSame('Recipient Chosen Name', $member->name);
        $this->assertTrue($member->is_active);
        $this->assertFalse($member->is_admin);
        $this->assertNotNull($member->email_verified_at);
        $this->assertTrue(Hash::check($password, $member->password));
        $this->assertNotSame($password, $member->password);
        $this->assertNotNull(Invitation::query()->sole()->accepted_at);
        $this->assertSame(1, User::query()->where('is_admin', true)->where('is_active', true)->count());
        $this->assertAuthenticatedAs($member, 'web');
    }

    public function test_claim_is_single_use_and_invalid_claims_have_a_generic_response(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->sendInvitation($admin, 'recipient@example.test');
        $token = $this->sentInvitationToken();
        $password = Str::random(32);
        $payload = [
            'token' => $token,
            'name' => 'Recipient',
            'password' => $password,
            'password_confirmation' => $password,
        ];

        $this->postJson('/api/invitations/claim', $payload)->assertOk();
        $used = $this->postJson('/api/invitations/claim', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This invitation link is invalid, expired, already used, or cannot be claimed.');
        $invalid = $this->postJson('/api/invitations/claim', [...$payload, 'token' => Str::random(64)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This invitation link is invalid, expired, already used, or cannot be claimed.');

        $this->assertSame($used->json('message'), $invalid->json('message'));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_expired_invitation_is_rejected_without_revealing_its_state(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->sendInvitation($admin, 'recipient@example.test');
        $token = $this->sentInvitationToken();
        $password = Str::random(32);
        $this->travel((int) config('auth.invitations.expire') + 1)->minutes();

        $this->postJson('/api/invitations/claim', [
            'token' => $token,
            'name' => 'Recipient',
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This invitation link is invalid, expired, already used, or cannot be claimed.');

        $this->assertDatabaseCount('users', 1);
        $this->assertNull(Invitation::query()->sole()->accepted_at);
    }

    public function test_revoked_invitation_cannot_be_claimed(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->sendInvitation($admin, 'recipient@example.test');
        $token = $this->sentInvitationToken();
        $password = Str::random(32);
        $invitation = Invitation::query()->sole();

        $this->actingAs($admin, 'web')
            ->deleteJson('/api/admin/invitations/'.$invitation->id)
            ->assertOk()
            ->assertJsonPath('message', 'Invitation revoked.');

        $this->postJson('/api/invitations/claim', [
            'token' => $token,
            'name' => 'Recipient',
            'password' => $password,
            'password_confirmation' => $password,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This invitation link is invalid, expired, already used, or cannot be claimed.');

        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_resend_rotates_token_and_invalidates_previous_link(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->sendInvitation($admin, 'recipient@example.test');
        $oldToken = $this->sentInvitationToken();
        $invitation = Invitation::query()->sole();

        $this->actingAs($admin, 'web')
            ->postJson('/api/admin/invitations/'.$invitation->id.'/resend')
            ->assertOk()
            ->assertJsonPath('message', 'Invitation email sent again.');

        $tokens = $this->sentInvitationTokens();
        $this->assertCount(2, $tokens);
        $this->assertSame($oldToken, $tokens[0]);
        $this->assertNotSame($oldToken, $tokens[1]);
        $this->assertSame(hash('sha256', $tokens[1]), $invitation->fresh()->token_hash);

        $password = Str::random(32);
        $claim = [
            'name' => 'Recipient',
            'password' => $password,
            'password_confirmation' => $password,
        ];
        $this->postJson('/api/invitations/claim', [...$claim, 'token' => $oldToken])->assertUnprocessable();
        $this->postJson('/api/invitations/claim', [...$claim, 'token' => $tokens[1]])->assertOk();
    }

    public function test_resending_same_normalized_email_reuses_one_pending_invitation(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $this->sendInvitation($admin, 'MEMBER@example.test');
        $firstToken = $this->sentInvitationToken();
        $this->sendInvitation($admin, ' member@EXAMPLE.test ');
        $tokens = $this->sentInvitationTokens();

        $this->assertCount(1, Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->get());
        $this->assertCount(2, $tokens);
        $this->assertSame($firstToken, $tokens[0]);
        $this->assertNotSame($tokens[0], $tokens[1]);
        $this->assertSame('member@example.test', Invitation::query()->sole()->email);
    }

    public function test_existing_account_cannot_be_invited(): void
    {
        Mail::fake();
        $admin = $this->admin();
        User::factory()->create(['email' => 'existing@example.test']);

        $this->actingAs($admin, 'web')
            ->postJson('/api/admin/invitations', ['email' => ' EXISTING@example.test '])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This address cannot receive an invitation.');

        $this->assertDatabaseCount('invitations', 0);
        Mail::assertNothingSent();
    }

    public function test_smtp_failure_returns_safe_error_logs_no_recipient_and_keeps_invite_resendable(): void
    {
        $admin = $this->admin();
        $failureText = Str::random(32);
        $pendingMail = Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')
            ->once()
            ->with(Mockery::type(InvitationMail::class))
            ->andThrow(new TransportException($failureText));

        Mail::shouldReceive('to')
            ->once()
            ->with('recipient@example.test')
            ->andReturn($pendingMail);
        Log::shouldReceive('warning')
            ->once()
            ->with('Invitation email delivery failed.', [
                'exception_type' => TransportException::class,
            ]);

        $response = $this->actingAs($admin, 'web')
            ->postJson('/api/admin/invitations', ['email' => 'recipient@example.test'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'The invitation email could not be sent. The pending invitation can be resent.');

        $this->assertStringNotContainsString('recipient@example.test', $response->getContent());
        $this->assertStringNotContainsString($failureText, $response->getContent());
        $this->assertDatabaseCount('invitations', 1);
        $this->assertNull(Invitation::query()->sole()->accepted_at);
    }

    public function test_database_rejects_normalized_duplicate_active_invitation_email(): void
    {
        $admin = $this->admin();
        Invitation::query()->create([
            'email' => 'member@example.test',
            'token_hash' => hash('sha256', Str::random(64)),
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('invitations')->insert([
            'email' => ' MEMBER@Example.Test ',
            'token_hash' => hash('sha256', Str::random(64)),
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create([
            'name' => 'Household Admin',
            'email' => 'admin@example.test',
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    private function sendInvitation(User $admin, string $email): void
    {
        $this->actingAs($admin, 'web')
            ->postJson('/api/admin/invitations', ['email' => $email])
            ->assertCreated();
    }

    private function sentInvitationToken(): string
    {
        $token = '';
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$token): bool {
            $token = $this->extractToken($mail);

            return $token !== '';
        });

        return $token;
    }

    /** @return array<int, string> */
    private function sentInvitationTokens(): array
    {
        $tokens = [];
        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$tokens): bool {
            $tokens[] = $this->extractToken($mail);

            return true;
        });

        return $tokens;
    }

    private function extractToken(InvitationMail $mail): string
    {
        preg_match('/#token=([A-Za-z0-9]+)/', $mail->render(), $matches);

        return $matches[1] ?? '';
    }
}
