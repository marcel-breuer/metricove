<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotesModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $memberSequence = 0;

    public function test_notes_are_private_until_shared_and_shared_notes_are_read_only_without_folder_disclosure(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $viewer = $this->member();
        $this->enable($admin, true);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true]);

        $folderId = (int) $this->actingAs($owner, 'web')->postJson('/api/notes/folders', ['name' => 'Private plans'])
            ->assertCreated()->json('folder.id');
        $noteId = (int) $this->actingAs($owner, 'web')->postJson('/api/notes', [
            'title' => 'Weekend plan',
            'body' => "# Cabin\n\nBring **warm clothes**. <script>alert(1)</script> [unsafe](javascript:alert(1))",
            'folder_id' => $folderId,
            'tags' => ['Trip', 'Friends'],
        ])->assertCreated()->assertJsonPath('note.shared_with_household', false)->json('note.id');

        $this->actingAs($admin, 'web')->getJson('/api/notes')->assertOk()->assertJsonCount(0, 'notes');
        $this->actingAs($viewer, 'web')->getJson("/api/notes/{$noteId}")->assertNotFound();

        $this->actingAs($owner, 'web')->getJson('/api/notes?search=cabin&tag=trip')->assertOk()
            ->assertJsonCount(1, 'notes')->assertJsonPath('notes.0.title', 'Weekend plan');
        $this->actingAs($viewer, 'web')->getJson('/api/notes/folders')->assertOk()->assertJsonCount(0, 'folders');

        $this->actingAs($owner, 'web')->patchJson("/api/notes/{$noteId}", [
            'expected_version' => 1,
            'shared_with_household' => true,
        ])->assertOk()->assertJsonPath('note.shared_with_household', true);

        $sharedNote = $this->actingAs($viewer, 'web')->getJson("/api/notes/{$noteId}")->assertOk()
            ->assertJsonPath('note.is_owner', false)->assertJsonPath('note.can_edit', false)
            ->assertJsonPath('note.folder', null)->assertJsonPath('note.body', "# Cabin\n\nBring **warm clothes**. <script>alert(1)</script> [unsafe](javascript:alert(1))")
            ->assertJsonMissing(['note.markdown_html' => 'alert(1)']);
        $markdownHtml = (string) $sharedNote->json('note.markdown_html');
        $this->assertStringNotContainsString('<script', $markdownHtml);
        $this->assertStringNotContainsString('javascript:', $markdownHtml);
        $this->actingAs($viewer, 'web')->patchJson("/api/notes/{$noteId}", [
            'expected_version' => 2,
            'title' => 'Unauthorized edit',
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->getJson("/api/notes/{$noteId}")->assertOk()
            ->assertJsonPath('note.title', 'Weekend plan')
            ->assertJsonPath('note.folder', null);

        $this->actingAs($owner, 'web')->patchJson("/api/notes/{$noteId}/archive", [
            'archived' => true,
            'expected_version' => 2,
        ])->assertOk()->assertJsonPath('note.shared_with_household', false);
        $this->actingAs($viewer, 'web')->getJson('/api/notes')->assertOk()->assertJsonCount(0, 'notes');
        $this->actingAs($admin, 'web')->getJson("/api/notes/{$noteId}")->assertNotFound();
    }

    public function test_notes_require_module_capabilities_and_disabled_module_preserves_data(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $this->enable($admin, true);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true]);
        $noteId = (int) $this->actingAs($owner, 'web')->postJson('/api/notes', [
            'title' => 'House notes', 'body' => 'Keep the keys by the door.',
        ])->assertCreated()->json('note.id');

        $this->enable($admin, false);
        $this->actingAs($owner, 'web')->getJson('/api/notes')->assertNotFound();
        $this->actingAs($owner, 'web')->patchJson("/api/notes/{$noteId}", [
            'expected_version' => 1, 'title' => 'Hidden edit',
        ])->assertNotFound();
        $this->assertDatabaseHas('notes', ['id' => $noteId, 'title' => 'House notes']);

        $this->enable($admin, true);
        $this->actingAs($owner, 'web')->getJson("/api/notes/{$noteId}")->assertOk()
            ->assertJsonPath('note.title', 'House notes');
    }

    public function test_note_folders_are_owner_scoped_and_stale_updates_conflict(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $other = $this->member();
        $this->enable($admin, true);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($other, ['view' => true, 'create' => true, 'edit' => true]);

        $folderId = (int) $this->actingAs($owner, 'web')->postJson('/api/notes/folders', ['name' => 'Personal'])
            ->assertCreated()->json('folder.id');
        $this->actingAs($other, 'web')->patchJson("/api/notes/folders/{$folderId}", [
            'name' => 'Stolen folder', 'expected_version' => 1,
        ])->assertNotFound();

        $this->actingAs($owner, 'web')->patchJson("/api/notes/folders/{$folderId}", [
            'name' => 'Updated', 'expected_version' => 1,
        ])->assertOk()->assertJsonPath('folder.version', 2);
        $this->actingAs($owner, 'web')->patchJson("/api/notes/folders/{$folderId}", [
            'name' => 'Stale', 'expected_version' => 1,
        ])->assertConflict();
    }

    private function member(bool $admin = false): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
        $this->memberSequence++;

        return User::factory()->create([
            'name' => 'Notes Member '.$this->memberSequence,
            'email' => 'notes-'.$this->memberSequence.'@example.test',
            'is_admin' => $admin,
            'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'notes'],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }

    private function enable(User $admin, bool $enabled): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, 'notes', $enabled);
    }
}
