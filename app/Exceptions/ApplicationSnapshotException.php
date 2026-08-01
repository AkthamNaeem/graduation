<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ApplicationSnapshotException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $errors = [],
        public readonly mixed $data = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
