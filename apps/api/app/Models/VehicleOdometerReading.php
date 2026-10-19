<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleOdometerReading extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'vehicle_id',
        'owner_user_id',
        'created_by_user_id',
        'is_private',
        'reading_date',
        'value',
        'note',
    ];

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'reading_date' => 'date:Y-m-d', 'value' => 'decimal:3'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(VehicleOdometerRevision::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function moduleKey(): string
    {
        return 'vehicles';
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
