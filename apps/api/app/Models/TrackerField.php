<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackerField extends Model
{
    protected $fillable = [
        'tracker_definition_id',
        'field_key',
        'label',
        'data_type',
        'unit',
        'is_required',
        'minimum',
        'maximum',
        'sort_order',
        'created_version',
        'retired_version',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'minimum' => 'decimal:6',
            'maximum' => 'decimal:6',
            'sort_order' => 'integer',
            'created_version' => 'integer',
            'retired_version' => 'integer',
        ];
    }

    /** @return BelongsTo<TrackerDefinition, $this> */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(TrackerDefinition::class, 'tracker_definition_id');
    }

    /** @return HasMany<TrackerEntryValue, $this> */
    public function entryValues(): HasMany
    {
        return $this->hasMany(TrackerEntryValue::class);
    }
}
