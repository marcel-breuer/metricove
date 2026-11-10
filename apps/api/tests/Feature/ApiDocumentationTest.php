<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_api_reference_to_login(): void
    {
        $this->get('/docs/api')->assertRedirect('/login');
    }

    public function test_active_members_can_open_the_api_reference_and_openapi_document(): void
    {
        config(['scramble.cache.store' => 'array']);
        $member = User::factory()->create(['is_active' => true]);

        $this->actingAs($member, 'web')->get('/docs/api')->assertOk();

        $specification = $this->actingAs($member, 'web')
            ->getJson('/docs/api.json')
            ->assertOk()
            ->assertJsonStructure(['openapi', 'info', 'paths'])
            ->json();

        $this->assertArrayHasKey('/health', $specification['paths']);
        $this->assertArrayNotHasKey('/mcp', $specification['paths']);
    }
}
