<?php

namespace App\Exceptions;

use RuntimeException;

class LocalOcrUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Local meter photo recognition is unavailable.');
    }
}
