<?php

namespace App\Data\RecommendationMl;

use App\Exceptions\RecommendationMl\MlRecommendationConfigurationException;

final readonly class MlClientConfiguration
{
    private const MAX_CONNECT_TIMEOUT_SECONDS = 30;

    private const MAX_TIMEOUT_SECONDS = 120;

    private function __construct(
        public string $baseUrl,
        private string $serviceToken,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxJobsPerRequest,
        public int $maxResults,
        public string $apiContractVersion,
        public string $bundleVersion,
        public string $modelVersion,
        public string $featureSchemaVersion,
        public string $explanationContractVersion,
        public string $scoreTransformVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function fromArray(array $configuration): self
    {
        $baseUrl = self::requiredString($configuration, 'base_url');
        $token = self::requiredString($configuration, 'service_token');
        $parts = parse_url($baseUrl);

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            self::fail('ML_CONFIGURATION_BASE_URL_INVALID');
        }

        if (strlen($token) < 32) {
            self::fail('ML_CONFIGURATION_TOKEN_INVALID');
        }

        $connectTimeout = self::requiredInteger($configuration, 'connect_timeout_seconds');
        $timeout = self::requiredInteger($configuration, 'timeout_seconds');
        $maxJobs = self::requiredInteger($configuration, 'max_jobs_per_request');
        $maxResults = self::requiredInteger($configuration, 'max_results');

        if ($connectTimeout < 1 || $connectTimeout > self::MAX_CONNECT_TIMEOUT_SECONDS
            || $timeout < $connectTimeout
            || $timeout > self::MAX_TIMEOUT_SECONDS) {
            self::fail('ML_CONFIGURATION_TIMEOUT_INVALID');
        }

        if ($maxJobs < 1 || $maxJobs > 500
            || $maxResults < 1
            || $maxResults > 100
            || $maxResults > $maxJobs) {
            self::fail('ML_CONFIGURATION_LIMIT_INVALID');
        }

        return new self(
            baseUrl: rtrim($baseUrl, '/'),
            serviceToken: $token,
            connectTimeoutSeconds: $connectTimeout,
            timeoutSeconds: $timeout,
            maxJobsPerRequest: $maxJobs,
            maxResults: $maxResults,
            apiContractVersion: self::version($configuration, 'api_contract_version'),
            bundleVersion: self::version($configuration, 'bundle_version'),
            modelVersion: self::version($configuration, 'model_version'),
            featureSchemaVersion: self::version($configuration, 'feature_schema_version'),
            explanationContractVersion: self::version(
                $configuration,
                'explanation_contract_version',
            ),
            scoreTransformVersion: self::version($configuration, 'score_transform_version'),
        );
    }

    public function serviceToken(): string
    {
        return $this->serviceToken;
    }

    /**
     * @return array<string, int|string>
     */
    public function safeValues(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'connect_timeout_seconds' => $this->connectTimeoutSeconds,
            'timeout_seconds' => $this->timeoutSeconds,
            'max_jobs_per_request' => $this->maxJobsPerRequest,
            'max_results' => $this->maxResults,
            'api_contract_version' => $this->apiContractVersion,
            'bundle_version' => $this->bundleVersion,
            'model_version' => $this->modelVersion,
            'feature_schema_version' => $this->featureSchemaVersion,
            'explanation_contract_version' => $this->explanationContractVersion,
            'score_transform_version' => $this->scoreTransformVersion,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function requiredString(array $configuration, string $key): string
    {
        $value = $configuration[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            self::fail('ML_CONFIGURATION_MISSING');
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function requiredInteger(array $configuration, string $key): int
    {
        $value = $configuration[$key] ?? null;
        if (! is_int($value)) {
            self::fail('ML_CONFIGURATION_INVALID');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function version(array $configuration, string $key): string
    {
        $value = self::requiredString($configuration, $key);
        if (strlen($value) > 128) {
            self::fail('ML_CONFIGURATION_VERSION_INVALID');
        }

        return $value;
    }

    private static function fail(string $code): never
    {
        throw new MlRecommendationConfigurationException(
            internalCode: $code,
            operation: 'configuration',
        );
    }
}
