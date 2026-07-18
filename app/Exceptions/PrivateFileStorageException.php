<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class PrivateFileStorageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 503,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
