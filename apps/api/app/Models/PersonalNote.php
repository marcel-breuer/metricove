<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PersonalNote extends Model implements ModuleResource
{
    use HasResourcePermissions;
    use SoftDeletes;

    protected $table = 'notes';

    protected $fillable = [
        'owner_user_id', 'folder_id', 'title', 'body', 'shared_with_household', 'archived_at', 'version',
    ];

    protected function casts(): array
    {
        return [
            'shared_with_household' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<NoteFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(NoteFolder::class, 'folder_id');
    }

    /** @return BelongsToMany<NoteTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(NoteTag::class, 'note_note_tag', 'note_id', 'note_tag_id');
    }

    public function moduleKey(): string
    {
        return 'notes';
    }

    public function ownerUserId(): ?int
    {
        return (int) $this->owner_user_id;
    }

    public function isPrivate(): bool
    {
        return ! (bool) $this->shared_with_household;
    }

    /** @param Builder<PersonalNote> $query */
    public function scopeAvailableTo(Builder $query, User $member): Builder
    {
        return $query->where(function (Builder $visible) use ($member): void {
            $visible->where('owner_user_id', $member->id)
                ->orWhere(fn (Builder $shared) => $shared->where('shared_with_household', true)->whereNull('archived_at'));
        });
    }
}
