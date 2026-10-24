<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenanceRecord extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'vehicle_id',
        'owner_user_id',
        'created_by_user_id',
        'is_private',
        'title',
        'service_type',
        'occurred_on',
        'next_due_on',
        'odometer_value',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'occurred_on' => 'date:Y-m-d',
            'next_due_on' => 'date:Y-m-d',
            'odometer_value' => 'decimal:3',
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
