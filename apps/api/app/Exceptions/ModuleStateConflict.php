<?php

namespace App\Exceptions;

use RuntimeException;

class ModuleStateConflict extends RuntimeException
{
    /** @param list<string> $moduleKeys */
    public function __construct(
        public readonly string $reason,
        public readonly array $moduleKeys = [],
    ) {
        parent::__construct($reason);
    }
}
