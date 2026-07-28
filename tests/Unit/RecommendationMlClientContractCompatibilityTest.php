<?php

namespace Tests\Unit;

use App\Data\RecommendationMl\MlClientConfiguration;
use App\Data\RecommendationMl\MlRankRequest;
use App\Data\RecommendationMl\MlRankResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class RecommendationMlClientContractCompatibilityTest extends TestCase
{
    private const CONTRACT_DIRECTORY =
        'services/ml-recommendation/data/contracts/inference/v1';

    public function test_contract_artifact_hashes_match_the_frozen_phase_12_release(): void
    {
        $expected = [
            'openapi.json' => 'b73b11b5fa67c40927e5a05ab72e2d2f7b292fa3149f0d945ae74be08f7ca96d',
            'request.example.json' => 'a21d59ab86e6b01b69f7bd2c7e7e4a4535c3cbf0f4d06e3f014d32b940b0ab91',
            'response.example.json' => '748a4716bad9e57c85cc50375ce8762cdaaf37106b70228233ce2fa684b66bc0',
            'contract_manifest.json' => 'a51e8f4e74189ccb086bdb7fe32816c6e56953533f3c77243e50650be0bf9cb2',
        ];

        foreach ($expected as $file => $hash) {
            $this->assertSame(
                $hash,
                hash_file('sha256', base_path(self::CONTRACT_DIRECTORY.'/'.$file)),
                $file,
            );
        }
    }

    public function test_phase_12_request_example_validates_against_its_openapi_schema(): void
    {
        $openApi = $this->fixtureJson('openapi.json');
        $request = $this->fixtureJson('request.example.json');
        $schema = $openApi['components']['schemas']['RankRequest'];

        $this->assertSame(
            [],
            $this->schemaErrors($request, $schema, $openApi),
        );
    }

    public function test_phase_12_request_example_round_trips_through_laravel_dtos(): void
    {
        $fixture = $this->fixtureJson('request.example.json');

        $this->assertEquals($fixture, MlRankRequest::fromArray($fixture)->toArray());
    }

    public function test_phase_12_response_example_parses_through_laravel_dtos(): void
    {
        $request = MlRankRequest::fromArray($this->fixtureJson('request.example.json'));
        $response = MlRankResponse::fromArray(
            $this->fixtureJson('response.example.json'),
            $request,
            MlClientConfiguration::fromArray($this->configuration()),
        );

        $this->assertSame($request->requestId, $response->requestId);
        $this->assertSame(3, $response->predictionCount);
        $this->assertSame([123, 122, 121], array_map(
            fn ($prediction): int => $prediction->jobId,
            $response->predictions,
        ));
    }

    public function test_laravel_runtime_has_no_dependency_on_contract_artifact_paths(): void
    {
        $directories = [
            base_path('app/Contracts/RecommendationMl'),
            base_path('app/Data/RecommendationMl'),
            base_path('app/Exceptions/RecommendationMl'),
            base_path('app/Services/RecommendationMl'),
            base_path('app/Support/RecommendationMl'),
        ];

        foreach ($directories as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                $this->assertStringNotContainsString('services/ml-recommendation', $source);
                $this->assertStringNotContainsString('data/contracts/inference', $source);
            }
        }
    }

    public function test_static_transport_security_policy_is_enforced(): void
    {
        $source = file_get_contents(
            base_path('app/Services/RecommendationMl/RecommendationMlClient.php'),
        );

        $this->assertStringContainsString('X-ML-Service-Token', $source);
        $this->assertStringContainsString('withoutRedirecting()', $source);
        $this->assertStringContainsString('connectTimeout(', $source);
        $this->assertStringContainsString('timeout(', $source);
        $this->assertStringNotContainsString('->retry(', $source);
        $this->assertStringNotContainsString('Http::retry', $source);
        $this->assertStringNotContainsString('withCookies', $source);
        $this->assertStringNotContainsString('withToken', $source);
        $this->assertStringNotContainsString('Authorization', $source);
        $this->assertStringNotContainsString('Log::', $source);
    }

    public function test_example_environment_contains_no_real_service_token(): void
    {
        $environment = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            "ML_RECOMMENDATION_SERVICE_TOKEN=\n",
            str_replace("\r\n", "\n", $environment),
        );
        $this->assertStringNotContainsString(
            'phase13-local-integration-token',
            $environment,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureJson(string $file): array
    {
        $decoded = json_decode(
            file_get_contents(base_path(self::CONTRACT_DIRECTORY.'/'.$file)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        return [
            'base_url' => 'http://127.0.0.1:8100',
            'service_token' => str_repeat('x', 32),
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 10,
            'max_jobs_per_request' => 500,
            'max_results' => 100,
            'api_contract_version' => 'recommendation-ranking-api-v1',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'feature_schema_version' => 'job-rec-features-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $openApi
     * @return list<string>
     */
    private function schemaErrors(
        mixed $value,
        array $schema,
        array $openApi,
        string $path = '$',
    ): array {
        if (isset($schema['$ref'])) {
            $name = basename($schema['$ref']);

            return $this->schemaErrors(
                $value,
                $openApi['components']['schemas'][$name],
                $openApi,
                $path,
            );
        }

        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $candidate) {
                if ($this->schemaErrors($value, $candidate, $openApi, $path) === []) {
                    return [];
                }
            }

            return [$path.' does not match anyOf'];
        }

        $errors = [];
        $type = $schema['type'] ?? null;
        if ($type === 'null') {
            return $value === null ? [] : [$path.' must be null'];
        }
        if ($type === 'object') {
            if (! is_array($value) || array_is_list($value)) {
                return [$path.' must be an object'];
            }
            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = $path.'.'.$required.' is required';
                }
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                $unknown = array_diff(
                    array_keys($value),
                    array_keys($schema['properties'] ?? []),
                );
                foreach ($unknown as $key) {
                    $errors[] = $path.'.'.$key.' is unknown';
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (array_key_exists($key, $value)) {
                    $errors = array_merge(
                        $errors,
                        $this->schemaErrors($value[$key], $property, $openApi, $path.'.'.$key),
                    );
                }
            }
        } elseif ($type === 'array') {
            if (! is_array($value) || ! array_is_list($value)) {
                return [$path.' must be an array'];
            }
            if (count($value) < ($schema['minItems'] ?? 0)
                || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                $errors[] = $path.' has invalid item count';
            }
            foreach ($value as $index => $item) {
                $errors = array_merge(
                    $errors,
                    $this->schemaErrors(
                        $item,
                        $schema['items'],
                        $openApi,
                        $path.'.'.$index,
                    ),
                );
            }
        } elseif ($type === 'string') {
            if (! is_string($value)) {
                return [$path.' must be a string'];
            }
            if (mb_strlen($value) < ($schema['minLength'] ?? 0)
                || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)) {
                $errors[] = $path.' has invalid length';
            }
            if (($schema['format'] ?? null) === 'uuid'
                && preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',
                    $value,
                ) !== 1) {
                $errors[] = $path.' is not a UUID';
            }
        } elseif ($type === 'integer' && ! is_int($value)) {
            $errors[] = $path.' must be an integer';
        } elseif ($type === 'number' && ! is_int($value) && ! is_float($value)) {
            $errors[] = $path.' must be a number';
        }

        if ((is_int($value) || is_float($value))
            && ($value < ($schema['minimum'] ?? -INF)
                || $value > ($schema['maximum'] ?? INF)
                || (isset($schema['exclusiveMinimum'])
                    && $value <= $schema['exclusiveMinimum']))) {
            $errors[] = $path.' is outside numeric bounds';
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = $path.' does not match const';
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' is outside enum';
        }

        return $errors;
    }
}
