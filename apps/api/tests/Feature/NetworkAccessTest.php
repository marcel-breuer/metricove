<?php

namespace Tests\Feature;

use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\User;
use App\Services\NetworkAccess\ClientIpResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_allowlist_leaves_access_unrestricted(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])
            ->getJson('/api/setup/status')
            ->assertOk();
    }

    public function test_admin_can_configure_multiple_ipv4_ipv6_addresses_and_networks(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $this->actingAs($admin, 'web')
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('network_access.enabled', false)
            ->assertJsonPath('network_access.client_ip', '192.0.2.25');

        $this->patchJson('/api/admin/settings/network-access', [
            'allowed_ip_ranges' => [
                '192.0.2.25',
                '192.0.2.99/24',
                '2001:0db8::1',
                '192.0.2.0/24',
            ],
        ])->assertOk()
            ->assertJsonPath('network_access.enabled', true)
            ->assertJsonPath('network_access.allowed_ip_ranges', ['192.0.2.25', '192.0.2.0/24', '2001:db8::1']);

        $this->assertSame(
            ['192.0.2.25', '192.0.2.0/24', '2001:db8::1'],
            HouseholdSettings::query()->findOrFail(1)->ip_allowlist,
        );

        $auditEvent = HouseholdAuditEvent::query()->where('action', 'household.network_access_settings_changed')->sole();
        $this->assertSame(['enabled' => true, 'entry_count' => 3], $auditEvent->details);
        $this->assertStringNotContainsString('192.0.2.25', json_encode($auditEvent->details));
    }

    public function test_allowed_ipv4_and_ipv6_network_clients_can_access_the_api(): void
    {
        $settings = HouseholdSettings::query()->findOrFail(1);
        $settings->ip_allowlist = ['192.0.2.0/24', '2001:db8:abcd::/48'];
        $settings->save();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.250'])
            ->getJson('/api/setup/status')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:abcd:1::5'])
            ->getJson('/api/setup/status')
            ->assertOk();
    }

    public function test_addresses_outside_the_allowlist_cannot_use_auth_invitation_or_mcp_endpoints(): void
    {
        $settings = HouseholdSettings::query()->findOrFail(1);
        $settings->ip_allowlist = ['192.0.2.0/24'];
        $settings->save();
        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.50',
        ]);

        $this->getJson('/api/setup/status')->assertForbidden();
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'incorrect'])->assertForbidden();
        $this->postJson('/api/invitations/claim', [])->assertForbidden();
        $this->postJson('/api/mcp', [])->assertForbidden();
    }

    public function test_health_endpoint_remains_available_outside_the_allowlist(): void
    {
        $settings = HouseholdSettings::query()->findOrFail(1);
        $settings->ip_allowlist = ['192.0.2.0/24'];
        $settings->save();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->getJson('/api/health')
            ->assertOk();
    }

    public function test_admin_can_remove_all_ranges_to_disable_the_restriction(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $settings = HouseholdSettings::query()->findOrFail(1);
        $settings->ip_allowlist = ['192.0.2.0/24'];
        $settings->save();

        $this->actingAs($admin, 'web')
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])
            ->patchJson('/api/admin/settings/network-access', ['allowed_ip_ranges' => []])
            ->assertOk()
            ->assertJsonPath('network_access.enabled', false)
            ->assertJsonPath('network_access.allowed_ip_ranges', []);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->getJson('/api/setup/status')
            ->assertOk();
    }

    public function test_admin_must_confirm_when_the_new_allowlist_excludes_the_current_address(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $settings = HouseholdSettings::query()->findOrFail(1);
        $settings->ip_allowlist = ['192.0.2.0/24'];
        $settings->save();

        $this->actingAs($admin, 'web')
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])
            ->patchJson('/api/admin/settings/network-access', [
                'allowed_ip_ranges' => ['198.51.100.0/24'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'network_access_lockout');

        $this->assertSame(['192.0.2.0/24'], HouseholdSettings::query()->findOrFail(1)->ip_allowlist);

        $this->patchJson('/api/admin/settings/network-access', [
            'allowed_ip_ranges' => ['198.51.100.0/24'],
            'confirm_lockout' => true,
        ])->assertOk();

        $this->assertSame(['198.51.100.0/24'], HouseholdSettings::query()->findOrFail(1)->ip_allowlist);
    }

    public function test_non_admin_cannot_view_or_change_network_access_settings(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);

        $this->actingAs($member, 'web')
            ->getJson('/api/admin/settings')
            ->assertForbidden();

        $this->patchJson('/api/admin/settings/network-access', [
            'allowed_ip_ranges' => ['192.0.2.0/24'],
        ])->assertForbidden();

        $this->assertEmpty(HouseholdSettings::query()->findOrFail(1)->ip_allowlist);
        $this->assertDatabaseMissing('household_audit_events', ['action' => 'household.network_access_settings_changed']);
    }

    public function test_invalid_ranges_are_rejected_without_changing_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $this->actingAs($admin, 'web')
            ->patchJson('/api/admin/settings/network-access', [
                'allowed_ip_ranges' => ['192.0.2.0/33'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_ip_ranges.0');

        $this->assertEmpty(HouseholdSettings::query()->findOrFail(1)->ip_allowlist);
    }

    public function test_client_ip_resolver_ignores_forwarding_headers_unless_peer_is_trusted(): void
    {
        $request = request()->create('/api/setup/status', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.25',
        ]);

        $this->assertSame('198.51.100.10', app(ClientIpResolver::class)->resolve($request));

        config(['network_access.trusted_proxies' => ['198.51.100.0/24']]);
        $request = request()->create('/api/setup/status', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.25',
        ]);

        $this->assertSame('203.0.113.25', app(ClientIpResolver::class)->resolve($request));

        config(['network_access.trusted_proxies' => ['198.51.100.0/24', '203.0.113.0/24']]);
        $request = request()->create('/api/setup/status', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.99, 192.0.2.25, 203.0.113.10',
        ]);

        $this->assertSame('192.0.2.25', app(ClientIpResolver::class)->resolve($request));
    }
}
