<?php

namespace App\Exceptions\RecommendationMl;

use RuntimeException;

abstract class MlRecommendationException extends RuntimeException
{
    public function __construct(
        public readonly string $internalCode,
        public readonly ?string $requestId = null,
        public readonly ?int $httpStatus = null,
        public readonly string $operation = 'configuration',
        public readonly bool $retryable = false,
        public readonly ?string $serviceErrorCode = null,
    ) {
        parent::__construct($internalCode);
    }

    /**
     * @return array{
     *     internal_code: string,
     *     request_id: string|null,
     *     http_status: int|null,
     *     operation: string,
     *     retryable: bool,
     *     service_error_code?: string
     * }
     */
    public function safeContext(): array
    {
        $context = [
            'internal_code' => $this->internalCode,
            'request_id' => $this->requestId,
            'http_status' => $this->httpStatus,
            'operation' => $this->operation,
            'retryable' => $this->retryable,
        ];

        if ($this->serviceErrorCode !== null) {
            $context['service_error_code'] = $this->serviceErrorCode;
        }

        return $context;
    }
}
