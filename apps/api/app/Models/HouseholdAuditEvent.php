<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HouseholdAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'subject_user_id',
        'action',
        'details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
