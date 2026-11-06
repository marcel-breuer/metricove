<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property int $mcp_access_token_id
 * @property string $action
 * @property array<string, mixed> $payload
 * @property string $state
 * @property CarbonImmutable $expires_at
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $result
 */
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
