<?php

namespace App\Jobs\Middleware;

use App\Services\Modules\ModuleStateService;
use Closure;

class EnsureModuleEnabled
{
    public function __construct(private readonly string $moduleKey) {}

    /** @param object $job @param Closure(object): mixed $next */
    public function handle(object $job, Closure $next): mixed
    {
        if (! app(ModuleStateService::class)->isEnabled($this->moduleKey)) {
            return null;
        }

        return $next($job);
    }
}
