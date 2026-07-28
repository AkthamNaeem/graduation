<?php

namespace App\Services\RecommendationMl;

use App\Contracts\RecommendationMl\RecommendationMlClientContract;
use App\Data\RecommendationMl\MlClientConfiguration;
use App\Data\RecommendationMl\MlLivenessResult;
use App\Data\RecommendationMl\MlModelMetadata;
use App\Data\RecommendationMl\MlRankRequest;
use App\Data\RecommendationMl\MlRankResponse;
use App\Data\RecommendationMl\MlReadinessResult;
use App\Exceptions\RecommendationMl\MlRecommendationAuthenticationException;
use App\Exceptions\RecommendationMl\MlRecommendationContractException;
use App\Exceptions\RecommendationMl\MlRecommendationTransportException;
use App\Exceptions\RecommendationMl\MlRecommendationUnavailableException;
use App\Exceptions\RecommendationMl\MlRecommendationValidationException;
use App\Support\RecommendationMl\MlOutboundPayloadGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;

final class RecommendationMlClient implements RecommendationMlClientContract
{
    private readonly MlClientConfiguration $configuration;

    /**
     * @param  array<string, mixed>|MlClientConfiguration  $configuration
     */
    public function __construct(
        private readonly Factory $http,
        array|MlClientConfiguration $configuration,
        private readonly MlOutboundPayloadGuard $payloadGuard,
    ) {
        $this->configuration = $configuration instanceof MlClientConfiguration
            ? $configuration
            : MlClientConfiguration::fromArray($configuration);
    }

    public function live(): MlLivenessResult
    {
        return MlLivenessResult::fromArray(
            $this->request('GET', '/health/live', 'live'),
        );
    }

    public function ready(): MlReadinessResult
    {
        return MlReadinessResult::fromArray(
            $this->request('GET', '/health/ready', 'ready'),
            $this->configuration,
        );
    }

    public function metadata(): MlModelMetadata
    {
        return MlModelMetadata::fromArray(
            $this->request('GET', '/v1/model/metadata', 'metadata', authenticated: true),
            $this->configuration,
        );
    }

    public function rank(MlRankRequest $request): MlRankResponse
    {
        $request->assertForConfiguration($this->configuration);
        $payload = $request->toArray();
        $this->payloadGuard->assertSafe($payload, $request->requestId);

        return MlRankResponse::fromArray(
            $this->request(
                'POST',
                '/v1/recommendations/rank',
                'rank',
                $request->requestId,
                $payload,
                true,
            ),
            $request,
            $this->configuration,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function safeConfiguration(): array
    {
        return $this->configuration->safeValues();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        string $operation,
        ?string $requestId = null,
        ?array $payload = null,
        bool $authenticated = false,
    ): array {
        $request = $this->pendingRequest();
        if ($authenticated) {
            $request = $request->withHeaders([
                'X-ML-Service-Token' => $this->configuration->serviceToken(),
            ]);
        }

        try {
            $response = $method === 'POST'
                ? $request->post($this->configuration->baseUrl.$path, $payload ?? [])
                : $request->get($this->configuration->baseUrl.$path);
        } catch (ConnectionException) {
            throw new MlRecommendationTransportException(
                internalCode: 'ML_TRANSPORT_FAILED',
                requestId: $requestId,
                operation: $operation,
                retryable: true,
            );
        }

        $this->assertSuccessful($response, $operation, $requestId);

        try {
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MlRecommendationContractException(
                internalCode: 'ML_RESPONSE_JSON_INVALID',
                requestId: $requestId,
                httpStatus: $response->status(),
                operation: $operation,
            );
        }

        if (! is_array($decoded)) {
            throw new MlRecommendationContractException(
                internalCode: 'ML_RESPONSE_CONTRACT_INVALID',
                requestId: $requestId,
                httpStatus: $response->status(),
                operation: $operation,
            );
        }

        return $decoded;
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->configuration->connectTimeoutSeconds)
            ->timeout($this->configuration->timeoutSeconds)
            ->withoutRedirecting();
    }

    private function assertSuccessful(
        Response $response,
        string $operation,
        ?string $requestId,
    ): void {
        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 401) {
            throw new MlRecommendationAuthenticationException(
                internalCode: 'ML_AUTHENTICATION_FAILED',
                requestId: $requestId,
                httpStatus: 401,
                operation: $operation,
            );
        }

        if ($status === 422) {
            throw new MlRecommendationValidationException(
                internalCode: 'ML_SERVICE_VALIDATION_FAILED',
                requestId: $requestId,
                httpStatus: 422,
                operation: $operation,
                serviceErrorCode: $this->safeServiceErrorCode($response),
            );
        }

        if ($status === 429 || $status === 503 || $status >= 500) {
            throw new MlRecommendationUnavailableException(
                internalCode: $status === 429
                    ? 'ML_SERVICE_RATE_LIMITED'
                    : 'ML_SERVICE_UNAVAILABLE',
                requestId: $requestId,
                httpStatus: $status,
                operation: $operation,
                retryable: true,
            );
        }

        throw new MlRecommendationContractException(
            internalCode: 'ML_HTTP_STATUS_UNEXPECTED',
            requestId: $requestId,
            httpStatus: $status,
            operation: $operation,
        );
    }

    private function safeServiceErrorCode(Response $response): ?string
    {
        try {
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($body)) {
            return null;
        }

        $candidates = [
            $body['code'] ?? null,
            is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : null,
            is_array($body['detail'] ?? null) ? ($body['detail']['code'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate)
                && preg_match('/^[A-Z0-9_.-]{1,100}$/D', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }
}
