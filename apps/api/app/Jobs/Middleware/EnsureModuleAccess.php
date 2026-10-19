<?php

namespace App\Jobs\Middleware;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class EnsureModuleAccess
{
    public function __construct(
        private readonly int $memberId,
        private readonly string $moduleKey,
        private readonly ModuleCapability $capability,
        private readonly ?Model $resource = null,
    ) {}

    /** @param object $job @param \Closure(object): mixed $next */
    public function handle(object $job, \Closure $next): mixed
    {
        $member = User::query()->find($this->memberId);

        if (! $member instanceof User) {
            return null;
        }

        try {
            app(ModuleAccessService::class)->authorize(
                $member,
                $this->moduleKey,
                $this->capability,
                $this->resource,
            );
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return null;
        }

        return $next($job);
    }
}
