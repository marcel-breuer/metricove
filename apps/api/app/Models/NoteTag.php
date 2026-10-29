<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NoteTag extends Model
{
    protected $fillable = ['owner_user_id', 'name', 'normalized_name'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsToMany<PersonalNote, $this> */
    public function notes(): BelongsToMany
    {
        return $this->belongsToMany(PersonalNote::class, 'note_note_tag', 'note_tag_id', 'note_id');
    }
}
