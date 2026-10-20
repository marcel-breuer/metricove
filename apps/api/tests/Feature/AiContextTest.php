<?php

namespace Tests\Feature;

use App\Models\AiContextEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_create_read_update_and_delete_own_context(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $payload = ['scope' => 'user', 'kind' => 'preference', 'content' => 'Prefer concise answers'];
        $id = $this->postJson('/api/ai/context', $payload)->assertCreated()->json('item.id');
        $this->getJson('/api/ai/context')->assertOk()->assertJsonCount(1, 'items');
        $this->putJson("/api/ai/context/{$id}", [...$payload, 'content' => 'Prefer tables'])
            ->assertOk()->assertJsonPath('item.content', 'Prefer tables');
        $this->deleteJson("/api/ai/context/{$id}")->assertOk();
        $this->assertDatabaseMissing('ai_context_entries', ['id' => $id]);
    }

    public function test_member_cannot_access_another_members_context(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = AiContextEntry::create([
            'owner_user_id' => $owner->id, 'scope' => 'user',
            'kind' => 'preference', 'content' => 'Private',
        ]);
        $this->actingAs($other, 'web');
        $this->getJson('/api/ai/context')->assertOk()->assertJsonCount(0, 'items');
        $this->deleteJson("/api/ai/context/{$item->id}")->assertNotFound();
        $this->assertDatabaseHas('ai_context_entries', ['id' => $item->id]);
    }

    public function test_available_context_excludes_expired_and_disabled_records(): void
    {
        $user = User::factory()->create();
        foreach ([['enabled' => true, 'expires_at' => null], ['enabled' => false, 'expires_at' => null], ['enabled' => true, 'expires_at' => now()->subDay()]] as $values) {
            AiContextEntry::create([
                'owner_user_id' => $user->id, 'scope' => 'user',
                'kind' => 'preference', 'content' => 'Example', ...$values,
            ]);
        }
        $this->assertSame(1, AiContextEntry::availableTo($user->id)->count());
    }
}
