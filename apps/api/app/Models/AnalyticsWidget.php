<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsWidget extends Model
{
    protected $fillable = [
        'user_id',
        'metric_key',
        'title',
        'range_key',
        'chart_type',
        'position',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'version' => 'integer',
        ];
    }
}
