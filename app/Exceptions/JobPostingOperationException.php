<?php

namespace App\Exceptions;

use RuntimeException;

class JobPostingOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
