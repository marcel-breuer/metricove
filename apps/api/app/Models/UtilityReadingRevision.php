<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtilityReadingRevision extends Model
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
            'old_value' => 'decimal:6',
            'new_value' => 'decimal:6',
        ];
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(UtilityReading::class, 'utility_reading_id');
    }
}
