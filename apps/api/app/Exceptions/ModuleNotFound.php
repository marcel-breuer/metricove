<?php

namespace App\Exceptions;

use RuntimeException;

class ModuleNotFound extends RuntimeException
{
    public function __construct(public readonly string $moduleKey)
    {
        parent::__construct('The requested module is not registered.');
    }
}
