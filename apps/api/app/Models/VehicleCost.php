<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleCost extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'vehicle_id',
        'owner_user_id',
        'created_by_user_id',
        'is_private',
        'category',
        'amount',
        'currency',
        'period_start',
        'period_end',
        'period',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'amount' => 'decimal:2',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
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
