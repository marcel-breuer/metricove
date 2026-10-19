<?php

namespace App\Exceptions;

use RuntimeException;

class ModuleDisabled extends RuntimeException
{
    public function __construct(public readonly string $moduleKey)
    {
        parent::__construct("The {$moduleKey} module is disabled.");
    }
}
