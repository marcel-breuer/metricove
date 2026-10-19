<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackerDefinition extends Model implements ModuleResource
{
    use HasFactory, HasResourcePermissions;

    protected $fillable = [
        'created_by_user_id',
        'owner_user_id',
        'name',
        'description',
        'is_private',
        'schema_version',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'schema_version' => 'integer',
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
        return $this->is_private;
    }

    /** @return HasMany<TrackerField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(TrackerField::class);
    }

    /** @return HasMany<TrackerSchemaRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(TrackerSchemaRevision::class);
    }

    /** @return HasMany<TrackerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TrackerEntry::class);
    }
}
