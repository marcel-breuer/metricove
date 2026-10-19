<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackerSchemaRevision extends Model
{
    protected $fillable = [
        'tracker_definition_id',
        'changed_by_user_id',
        'version',
        'schema_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<TrackerDefinition, $this> */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(TrackerDefinition::class, 'tracker_definition_id');
    }
}
