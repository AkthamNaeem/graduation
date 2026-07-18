<?php

namespace App\Exceptions;

use RuntimeException;

class ApplicationInternalNoteException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
