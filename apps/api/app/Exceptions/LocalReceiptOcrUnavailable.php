<?php

namespace App\Exceptions;

use RuntimeException;

class LocalReceiptOcrUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Local receipt recognition is unavailable.');
    }
}
