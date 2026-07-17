<?php

namespace App\Exceptions;

use RuntimeException;

class InterviewLifecycleException extends RuntimeException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
