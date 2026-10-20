<?php

namespace App\Services\Auth;

use LogicException;

class ConfiguredFrontendUrl
{
    public function route(string $routeName): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        if ($baseUrl === '') {
            throw new LogicException('The configured application URL must not be empty.');
        }

        $path = route($routeName, [], false);

        return $baseUrl.(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
