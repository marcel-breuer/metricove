<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpActionPreview extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'mcp_access_token_id',
        'action',
        'payload',
        'state',
        'expires_at',
        'idempotency_key',
        'result',
        'approved_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'result' => 'array',
            'expires_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(McpAccessToken::class, 'mcp_access_token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
