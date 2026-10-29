<?php

namespace App\Services\Notes;

use App\Enums\ModuleCapability;
use App\Models\NoteFolder;
use App\Models\NoteTag;
use App\Models\PersonalNote;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class NoteService
{
    private const MODULE = 'notes';

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @param array{search?: string, folder_id?: int, tag?: string, status?: string, page?: int} $filters
     * @return array{notes: list<array<string, mixed>>, pagination: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function index(User $actor, array $filters): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $query = $this->visibleNotes($actor)->with(['tags', 'folder']);

        if (($filters['status'] ?? 'active') === 'archived') {
            $query->whereNotNull('archived_at')->where('owner_user_id', $actor->id);
        } elseif (($filters['status'] ?? 'active') === 'active') {
            $query->whereNull('archived_at');
        }

        if (isset($filters['folder_id'])) {
            $query->where('folder_id', $filters['folder_id'])->where('owner_user_id', $actor->id);
        }

        if (isset($filters['tag'])) {
            $tag = mb_strtolower(trim($filters['tag']));
            $query->whereHas('tags', fn (Builder $tags) => $tags->where('note_tags.normalized_name', $tag));
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $needle = '%'.mb_strtolower(trim($filters['search'])).'%';
            $query->where(fn (Builder $search) => $search->whereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(body) LIKE ?', [$needle])
                ->orWhereHas('tags', fn (Builder $tags) => $tags->whereRaw('LOWER(note_tags.name) LIKE ?', [$needle])));
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(30, ['*'], 'page', $page);

        return [
            'notes' => $paginator->getCollection()->map(fn (PersonalNote $note): array => $this->present($actor, $note, false))->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $actor, int $noteId): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $note = $this->visibleNotes($actor)->with(['tags', 'folder'])->whereKey($noteId)->firstOrFail();
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $note);

        return $this->present($actor, $note, true);
    }

    public function preview(User $actor, string $markdown): string
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);

        $note = DB::transaction(function () use ($actor, $data): PersonalNote {
            $folder = $this->resolveFolder($actor, $data['folder_id'] ?? null);
            $note = PersonalNote::query()->create([
                'owner_user_id' => $actor->id,
                'folder_id' => $folder?->id,
                'title' => trim($data['title']),
                'body' => $data['body'],
                'shared_with_household' => false,
                'version' => 1,
            ]);
            $this->syncTags($actor, $note, $data['tags'] ?? []);

            return $note;
        }, 3);

        return $this->present($actor, $note->fresh(['tags', 'folder']), true);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $noteId, array $data): array
    {
        $note = DB::transaction(function () use ($actor, $noteId, $data): PersonalNote {
            $note = PersonalNote::query()->whereKey($noteId)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($actor, $note, ModuleCapability::Edit);
            $this->assertVersion($note, (int) $data['expected_version']);

            if (array_key_exists('shared_with_household', $data)) {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage, $note);
                $note->shared_with_household = (bool) $data['shared_with_household'];
            }
            if (array_key_exists('folder_id', $data)) {
                $note->folder_id = $this->resolveFolder($actor, $data['folder_id'])?->id;
            }
            foreach (['title', 'body'] as $field) {
                if (array_key_exists($field, $data)) {
                    $note->{$field} = $field === 'title' ? trim((string) $data[$field]) : $data[$field];
                }
            }
            if (array_key_exists('tags', $data)) {
                $this->syncTags($actor, $note, $data['tags']);
            }
            $note->version++;
            $note->save();

            return $note;
        }, 3);

        return $this->present($actor, $note->fresh(['tags', 'folder']), true);
    }

    public function archive(User $actor, int $noteId, bool $archived, int $expectedVersion): array
    {
        $note = DB::transaction(function () use ($actor, $noteId, $archived, $expectedVersion): PersonalNote {
            $note = PersonalNote::query()->whereKey($noteId)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($actor, $note, ModuleCapability::Edit);
            $this->assertVersion($note, $expectedVersion);
            $note->archived_at = $archived ? now() : null;
            if ($archived) {
                $note->shared_with_household = false;
            }
            $note->version++;
            $note->save();

            return $note;
        }, 3);

        return $this->present($actor, $note->fresh(['tags', 'folder']), true);
    }

    public function delete(User $actor, int $noteId): void
    {
        DB::transaction(function () use ($actor, $noteId): void {
            $note = PersonalNote::query()->whereKey($noteId)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($actor, $note, ModuleCapability::Edit);
            $note->forceDelete();
        }, 3);
    }

    /** @return list<array{id: int, name: string, version: int, note_count: int}> */
    public function folders(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return NoteFolder::query()->where('owner_user_id', $actor->id)->withCount('notes')
            ->orderBy('name')->orderBy('id')->get()
            ->map(fn (NoteFolder $folder): array => [
                'id' => (int) $folder->id,
                'name' => $folder->name,
                'version' => (int) $folder->version,
                'note_count' => (int) $folder->notes_count,
            ])->all();
    }

    /** @return array{id: int, name: string, version: int} */
    public function createFolder(User $actor, string $name): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        $folder = NoteFolder::query()->create(['owner_user_id' => $actor->id, 'name' => trim($name), 'version' => 1]);

        return ['id' => (int) $folder->id, 'name' => $folder->name, 'version' => (int) $folder->version];
    }

    /** @return array{id: int, name: string, version: int} */
    public function updateFolder(User $actor, int $folderId, string $name, int $expectedVersion): array
    {
        $folder = DB::transaction(function () use ($actor, $folderId, $name, $expectedVersion): NoteFolder {
            $folder = NoteFolder::query()->whereKey($folderId)->where('owner_user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit);
            if ($folder->version !== $expectedVersion) {
                throw new ConflictHttpException('This folder changed. Refresh and try again.');
            }
            $folder->name = trim($name);
            $folder->version++;
            $folder->save();

            return $folder;
        }, 3);

        return ['id' => (int) $folder->id, 'name' => $folder->name, 'version' => (int) $folder->version];
    }

    public function deleteFolder(User $actor, int $folderId): void
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit);
        NoteFolder::query()->whereKey($folderId)->where('owner_user_id', $actor->id)->delete();
    }

    /** @return Builder<PersonalNote> */
    private function visibleNotes(User $actor): Builder
    {
        return PersonalNote::query()->availableTo($actor);
    }

    private function authorizeOwner(User $actor, PersonalNote $note, ModuleCapability $capability): void
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $note);
        if ($note->ownerUserId() !== (int) $actor->id) {
            throw new AuthorizationException('Only the note owner can edit this note.');
        }
        $this->access->authorize($actor, self::MODULE, $capability, $note);
    }

    private function resolveFolder(User $actor, mixed $folderId): ?NoteFolder
    {
        if ($folderId === null) {
            return null;
        }

        return NoteFolder::query()->whereKey($folderId)->where('owner_user_id', $actor->id)->firstOrFail();
    }

    /** @param list<string> $names */
    private function syncTags(User $actor, PersonalNote $note, array $names): void
    {
        $tagIds = [];
        foreach (array_unique(array_map(static fn (string $name): string => trim($name), $names)) as $name) {
            if ($name === '') {
                continue;
            }
            $normalized = mb_strtolower($name);
            $tag = NoteTag::query()->firstOrCreate(
                ['owner_user_id' => $actor->id, 'normalized_name' => $normalized],
                ['name' => $name],
            );
            $tagIds[] = $tag->id;
        }
        $note->tags()->sync($tagIds);
    }

    private function assertVersion(PersonalNote $note, int $expectedVersion): void
    {
        if ($note->version !== $expectedVersion) {
            throw new ConflictHttpException('This note changed. Refresh and try again.');
        }
    }

    /** @return array<string, mixed> */
    private function present(User $actor, PersonalNote $note, bool $detail): array
    {
        $isOwner = $note->ownerUserId() === (int) $actor->id;
        $body = $detail ? $note->body : null;

        return [
            'id' => (int) $note->id,
            'title' => $note->title,
            'preview' => mb_substr(trim(strip_tags($note->body)), 0, 240),
            'body' => $body,
            'markdown_html' => $detail ? Str::markdown($note->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null,
            'folder' => $isOwner && $note->folder !== null ? ['id' => (int) $note->folder->id, 'name' => $note->folder->name] : null,
            'tags' => $note->tags->map(static fn (NoteTag $tag): array => ['id' => (int) $tag->id, 'name' => $tag->name])->values()->all(),
            'shared_with_household' => (bool) $note->shared_with_household,
            'is_owner' => $isOwner,
            'can_edit' => $isOwner && $this->hasCapability($actor, ModuleCapability::Edit, $note),
            'can_share' => $isOwner && $this->hasCapability($actor, ModuleCapability::Manage, $note),
            'archived_at' => $isOwner && $note->archived_at !== null
                ? CarbonImmutable::parse($note->archived_at)->toISOString()
                : null,
            'version' => (int) $note->version,
            'created_at' => $note->created_at?->toISOString(),
            'updated_at' => $note->updated_at?->toISOString(),
        ];
    }

    private function hasCapability(User $actor, ModuleCapability $capability, PersonalNote $note): bool
    {
        try {
            $this->access->authorize($actor, self::MODULE, $capability, $note);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
