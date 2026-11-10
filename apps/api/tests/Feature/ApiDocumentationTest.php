<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_api_reference_to_login(): void
    {
        $this->get('/docs/api')->assertRedirect('/login');
        $this->get('/docs/api.json')->assertRedirect('/login');
    }

    public function test_active_members_can_open_the_api_reference_and_openapi_document(): void
    {
        if (! Storage::disk('local')->exists('scribe/openapi.yaml')) {
            $this->artisan('scribe:generate')->assertExitCode(0);
        }
        $member = User::factory()->create(['is_active' => true]);

        $this->actingAs($member, 'web')->get('/docs/api')->assertOk();

        $specification = $this->actingAs($member, 'web')
            ->getJson('/docs/api.json')
            ->assertOk()
            ->assertJsonStructure(['openapi', 'info', 'paths'])
            ->json();

        $this->assertArrayHasKey('/api/health', $specification['paths']);
        $this->assertArrayNotHasKey('/api/mcp', $specification['paths']);
        $this->assertSame('cookie', $specification['components']['securitySchemes']['sessionCookie']['in']);
        $this->assertSame(config('session.cookie'), $specification['components']['securitySchemes']['sessionCookie']['name']);
        $this->assertSame([], $specification['paths']['/api/health']['get']['security']);
        $this->assertSame([['sessionCookie' => []]], $specification['paths']['/api/profile']['get']['security']);
    }
}
