<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackerEntryValue extends Model
{
    protected $fillable = [
        'tracker_entry_id',
        'tracker_field_id',
        'field_key_snapshot',
        'field_label_snapshot',
        'data_type_snapshot',
        'unit_snapshot',
        'decimal_value',
        'text_value',
        'date_value',
        'boolean_value',
    ];

    protected function casts(): array
    {
        return [
            'decimal_value' => 'decimal:6',
            'date_value' => 'date:Y-m-d',
            'boolean_value' => 'boolean',
        ];
    }

    /** @return BelongsTo<TrackerEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(TrackerEntry::class, 'tracker_entry_id');
    }

    /** @return BelongsTo<TrackerField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(TrackerField::class, 'tracker_field_id');
    }
}
