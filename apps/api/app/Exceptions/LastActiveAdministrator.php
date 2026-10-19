<?php

namespace App\Exceptions;

use RuntimeException;

class LastActiveAdministrator extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The last active administrator cannot be changed or removed.');
    }
}
