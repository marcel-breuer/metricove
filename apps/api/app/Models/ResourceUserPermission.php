<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourceUserPermission extends Model
{
    protected $fillable = [
        'resource_type',
        'resource_id',
        'user_id',
        'capability',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
