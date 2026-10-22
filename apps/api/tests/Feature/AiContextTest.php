<?php

namespace Tests\Feature;

use App\Models\AiContextEntry;
use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.provider' => 'openai-compatible',
            'ai.base_url' => 'https://assistant.example/v1',
            'ai.model' => 'test-model',
            'ai.api_key' => '',
            'ai.external_provider' => false,
            'ai.context_retention_days' => 30,
        ]);
    }

    public function test_members_can_manage_only_their_own_context_without_a_separate_ai_grant(): void
    {
        [$admin, $owner, $other] = $this->members();
        $this->actingAs($owner, 'web');

        $response = $this->postJson('/api/ai/context', [
            'scope' => 'project', 'project_key' => 'budget-app', 'kind' => 'preference',
            'content' => 'Prefer concise budget summaries.',
        ])->assertCreated()->assertJsonPath('item.version', 1);
        $id = $response->json('item.id');
        $this->assertDatabaseHas('ai_context_entries', [
            'id' => $id,
            'owner_user_id' => $owner->id,
            'project_key' => 'budget-app',
            'version' => 1,
        ]);

        $this->getJson('/api/ai/context?project_key=budget-app')->assertOk()->assertJsonCount(1, 'items');
        $this->putJson("/api/ai/context/{$id}", [
            'scope' => 'project', 'project_key' => 'budget-app', 'kind' => 'preference',
            'content' => 'Prefer tables for budget summaries.', 'expected_version' => 1,
        ])->assertOk()->assertJsonPath('item.version', 2);
        $this->putJson("/api/ai/context/{$id}", [
            'scope' => 'project', 'project_key' => 'budget-app', 'kind' => 'preference',
            'content' => 'Stale edit.', 'expected_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $this->actingAs($other, 'web')->getJson('/api/ai/context')->assertOk()->assertJsonCount(0, 'items');
        $this->deleteJson("/api/ai/context/{$id}")->assertNotFound();
        $this->actingAs($owner, 'web')->deleteJson("/api/ai/context/{$id}")->assertOk();
        $this->assertDatabaseMissing('ai_context_entries', ['id' => $id]);

        $this->actingAs($admin, 'web')->getJson('/api/ai/context')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_assistant_uses_only_relevant_user_and_matching_project_context(): void
    {
        [, $owner, $other] = $this->members();
        $this->entry($owner, 'user', null, 'preference', 'I prefer concise writing for project reports.');
        $this->entry($owner, 'project', 'budget-app', 'goal', 'Keep budget summaries focused on recurring costs.');
        $this->entry($owner, 'project', 'travel-app', 'preference', 'Use travel miles in itineraries.');
        $this->entry($other, 'project', 'budget-app', 'preference', 'Never disclose this other member context.');

        Http::fake(['*' => Http::response($this->assistantAnswer('A concise recurring cost summary.'))]);
        $this->actingAs($owner, 'web')->postJson('/api/ai/assistant', [
            'message' => 'How should I format my concise budget report?',
            'project_key' => 'budget-app',
        ])->assertOk();

        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0])->first()->data();
        $contextMessage = collect($sent['messages'])->last(fn (array $message): bool => ($message['role'] ?? null) === 'user' && str_contains((string) $message['content'], 'Saved personal context data'));
        $this->assertNotNull($contextMessage, json_encode($sent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('I prefer concise writing', $contextMessage['content']);
        $this->assertStringContainsString('recurring costs', $contextMessage['content']);
        $this->assertStringNotContainsString('travel miles', $contextMessage['content']);
        $this->assertStringNotContainsString('other member context', $contextMessage['content']);
    }

    public function test_opt_out_and_expired_or_disabled_entries_never_reach_provider(): void
    {
        [, $member] = $this->members();
        $this->entry($member, 'user', null, 'preference', 'I prefer concise answers.');
        $this->entry($member, 'user', null, 'preference', 'Expired private note.', ['expires_at' => now()->subDay()]);
        $this->entry($member, 'user', null, 'preference', 'Disabled private note.', ['enabled' => false]);
        Http::fake(['*' => Http::response($this->assistantAnswer('Hello.'))]);

        $this->actingAs($member, 'web')->patchJson('/api/ai/context/preferences', ['enabled' => false])
            ->assertOk()->assertJsonPath('enabled', false);
        $this->postJson('/api/ai/assistant', ['message' => 'How do I prefer my concise answers?'])->assertOk();
        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0])->first()->data();
        $this->assertStringNotContainsString('Saved personal context data', json_encode($sent, JSON_THROW_ON_ERROR));

        $this->patchJson('/api/ai/context/preferences', ['enabled' => true])->assertOk();
        Http::fake(['*' => Http::response($this->assistantAnswer('Hello again.'))]);
        $this->postJson('/api/ai/assistant', ['message' => 'How do I prefer my concise answers?'])->assertOk();
        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0])->last()->data();
        $payload = json_encode($sent, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('I prefer concise answers', $payload);
        $this->assertStringNotContainsString('Expired private note', $payload);
        $this->assertStringNotContainsString('Disabled private note', $payload);
    }

    public function test_context_is_available_as_a_core_feature(): void
    {
        [$admin] = $this->members();
        $this->actingAs($admin, 'web')->getJson('/api/ai/context')->assertOk();
    }

    /** @return array{User, User, User} */
    private function members(): array
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);

        return [$admin, $owner, $other];
    }

    /** @param array<string, mixed> $overrides */
    private function entry(User $owner, string $scope, ?string $project, string $kind, string $content, array $overrides = []): AiContextEntry
    {
        return AiContextEntry::query()->create([
            'owner_user_id' => $owner->id,
            'scope' => $scope,
            'project_key' => $project,
            'kind' => $kind,
            'content' => $content,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function assistantAnswer(string $answer): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $answer]]]];
    }
}
