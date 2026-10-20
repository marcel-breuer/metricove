<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiContextEntry extends Model
{
    protected $fillable = [
        'owner_user_id', 'scope', 'project_key', 'kind',
        'content', 'source', 'enabled', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'expires_at' => 'datetime'];
    }

    public function scopeAvailableTo(Builder $query, int $userId, ?string $projectKey = null): Builder
    {
        return $query->where('owner_user_id', $userId)
            ->where('enabled', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(function (Builder $q) use ($projectKey): void {
                $q->where('scope', 'user');
                if ($projectKey !== null) {
                    $q->orWhere(fn (Builder $project) => $project
                        ->where('scope', 'project')
                        ->where('project_key', $projectKey));
                }
            });
    }
}
