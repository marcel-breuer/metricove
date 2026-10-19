<?php

namespace App\Models\Concerns;

use App\Models\ResourceUserPermission;
use Illuminate\Database\Eloquent\Model;

trait HasResourcePermissions
{
    protected static function bootHasResourcePermissions(): void
    {
        static::deleting(function (Model $resource): void {
            ResourceUserPermission::query()
                ->where('resource_type', $resource->getMorphClass())
                ->where('resource_id', $resource->getKey())
                ->delete();
        });
    }
}
