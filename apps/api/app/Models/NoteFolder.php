<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoteFolder extends Model
{
    protected $fillable = ['owner_user_id', 'name', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<PersonalNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(PersonalNote::class, 'folder_id');
    }
}
