<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdReminder extends Model
{
    protected $fillable = [
        'recipient_user_id', 'source_module', 'source_type', 'source_id', 'occurrence_key', 'due_on',
        'read_at', 'dismissed_at', 'email_claimed_at', 'emailed_at', 'email_requested',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date:Y-m-d',
            'read_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'email_claimed_at' => 'immutable_datetime',
            'emailed_at' => 'immutable_datetime',
            'email_requested' => 'boolean',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
