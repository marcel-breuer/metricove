<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleState extends Model
{
    protected $primaryKey = 'module_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'module_key',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
