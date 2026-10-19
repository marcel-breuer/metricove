<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UtilityReading extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'utility_meter_id',
        'owner_user_id',
        'created_by_user_id',
        'is_private',
        'reading_date',
        'value',
        'note',
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'reading_date' => 'date:Y-m-d',
            'value' => 'decimal:6',
        ];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(UtilityMeter::class, 'utility_meter_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(UtilityReadingRevision::class, 'utility_reading_id');
    }

    public function moduleKey(): string
    {
        return 'utilities';
    }

    public function ownerUserId(): ?int
    {
        return $this->owner_user_id === null ? null : (int) $this->owner_user_id;
    }

    public function isPrivate(): bool
    {
        return (bool) $this->is_private;
    }
}
