<?php

namespace App\Exceptions;

use RuntimeException;

class EmailVerificationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $responseData
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly array $responseData = [],
    ) {
        parent::__construct($message);
    }
}
