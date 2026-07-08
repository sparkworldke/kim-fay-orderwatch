<?php

namespace App\Exceptions;

use RuntimeException;

class AcumaticaSyncStoppedException extends RuntimeException
{
    public function __construct(string $message = 'Sync stopped by user.')
    {
        parent::__construct($message);
    }
}
