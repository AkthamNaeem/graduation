<?php

namespace App\Exceptions;

use RuntimeException;

class CVParserException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
    ) {
        parent::__construct($reasonCode);
    }
}
