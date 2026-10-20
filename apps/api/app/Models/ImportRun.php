<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRun extends Model
{
    protected $fillable = [
        'user_id',
        'utility_meter_id',
        'idempotency_key',
        'file_sha256',
        'created_count',
        'skipped_count',
    ];

    protected function casts(): array
    {
        return ['created_count' => 'integer', 'skipped_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(UtilityMeter::class, 'utility_meter_id');
    }
}
