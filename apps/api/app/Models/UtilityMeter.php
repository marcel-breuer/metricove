<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UtilityMeter extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id',
        'created_by_user_id',
        'name',
        'meter_type',
        'unit',
        'description',
        'is_private',
        'alert_threshold',
    ];

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'alert_threshold' => 'decimal:6'];
    }

    public function readings(): HasMany
    {
        return $this->hasMany(UtilityReading::class, 'utility_meter_id');
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
