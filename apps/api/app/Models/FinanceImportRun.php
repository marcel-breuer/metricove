<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceImportRun extends Model
{
    protected $fillable = ['user_id', 'idempotency_key', 'fingerprint', 'created_count', 'skipped_count'];

    protected function casts(): array
    {
        return ['created_count' => 'integer', 'skipped_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
