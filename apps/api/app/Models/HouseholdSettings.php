<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HouseholdSettings extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'setup_completed_at' => 'immutable_datetime',
            'ai_api_key' => 'encrypted',
        ];
    }
}
