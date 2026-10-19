<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleOdometerRevision extends Model
{
    protected $fillable = [
        'changed_by_user_id',
        'old_reading_date',
        'new_reading_date',
        'old_value',
        'new_value',
        'old_note',
        'new_note',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'old_reading_date' => 'date:Y-m-d',
            'new_reading_date' => 'date:Y-m-d',
            'old_value' => 'decimal:3',
            'new_value' => 'decimal:3',
        ];
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(VehicleOdometerReading::class, 'vehicle_odometer_reading_id');
    }
}
