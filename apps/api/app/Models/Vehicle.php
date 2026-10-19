<?php

namespace App\Models;

use App\Contracts\ModuleResource;
use App\Models\Concerns\HasResourcePermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model implements ModuleResource
{
    use HasResourcePermissions;

    protected $fillable = [
        'owner_user_id',
        'created_by_user_id',
        'name',
        'make',
        'model',
        'model_year',
        'fuel_type',
        'odometer_unit',
        'description',
        'is_private',
    ];

    protected function casts(): array
    {
        return ['model_year' => 'integer', 'is_private' => 'boolean'];
    }

    public function odometerReadings(): HasMany
    {
        return $this->hasMany(VehicleOdometerReading::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceRecord::class)->orderByDesc('occurred_on')->orderByDesc('id');
    }

    public function insurancePolicies(): HasMany
    {
        return $this->hasMany(VehicleInsurancePolicy::class)->orderByDesc('valid_until')->orderByDesc('id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(VehicleCost::class)->orderByDesc('period_start')->orderByDesc('id');
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
