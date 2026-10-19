<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackerEntry extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'tracker_definition_id',
        'owner_user_id',
        'schema_version',
        'recorded_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function moduleKey(): string
    {
        return 'tracking';
    }

    public function ownerUserId(): ?int
    {
        return $this->owner_user_id === null ? null : (int) $this->owner_user_id;
    }

    public function isPrivate(): bool
    {
        return true;
    }

    /** @return BelongsTo<TrackerDefinition, $this> */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(TrackerDefinition::class, 'tracker_definition_id');
    }

    /** @return HasMany<TrackerEntryValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(TrackerEntryValue::class);
    }
}
