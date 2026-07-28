<?php

namespace Tests\Unit;

use App\Contracts\RecommendationMl\RecommendationMlClientContract;
use App\Data\RecommendationMl\MlClientConfiguration;
use App\Data\RecommendationMl\MlLivenessResult;
use App\Data\RecommendationMl\MlModelMetadata;
use App\Data\RecommendationMl\MlRankRequest;
use App\Data\RecommendationMl\MlRankResponse;
use App\Data\RecommendationMl\MlReadinessResult;
use App\Exceptions\RecommendationMl\MlRecommendationAuthenticationException;
use App\Exceptions\RecommendationMl\MlRecommendationConfigurationException;
use App\Exceptions\RecommendationMl\MlRecommendationContractException;
use App\Exceptions\RecommendationMl\MlRecommendationTransportException;
use App\Exceptions\RecommendationMl\MlRecommendationUnavailableException;
use App\Exceptions\RecommendationMl\MlRecommendationValidationException;
use App\Services\RecommendationMl\RecommendationMlClient;
use App\Support\RecommendationMl\MlOutboundPayloadGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class RecommendationMlClientTest extends TestCase
{
    private const TOKEN = 'test-ml-service-token-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('recommendation_ml', $this->validConfiguration());
        Http::preventStrayRequests();
    }

    public function test_valid_configuration_is_immutable_and_exposes_only_safe_values(): void
    {
        $configuration = MlClientConfiguration::fromArray($this->validConfiguration());

        $this->assertSame('http://ml.internal:8100', $configuration->baseUrl);
        $this->assertSame(2, $configuration->connectTimeoutSeconds);
        $this->assertArrayNotHasKey('service_token', $configuration->safeValues());
        $this->assertStringNotContainsString(self::TOKEN, json_encode(
            $configuration->safeValues(),
            JSON_THROW_ON_ERROR,
        ));
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function test_invalid_configuration_is_rejected_without_exposing_values(
        string $key,
        mixed $value,
    ): void {
        $configuration = $this->validConfiguration();
        $configuration[$key] = $value;

        try {
            MlClientConfiguration::fromArray($configuration);
            $this->fail('Expected invalid ML configuration to fail.');
        } catch (MlRecommendationConfigurationException $exception) {
            $serialized = json_encode($exception->safeContext(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $serialized);
            if (is_string($value) && $value !== '') {
                $this->assertStringNotContainsString($value, $exception->getMessage());
            }
        }
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            'missing URL' => ['base_url', null],
            'invalid URL' => ['base_url', 'not-a-url'],
            'credentials in URL' => ['base_url', 'http://user:pass@ml.internal:8100'],
            'query in URL' => ['base_url', 'http://ml.internal:8100?target=other'],
            'fragment in URL' => ['base_url', 'http://ml.internal:8100#fragment'],
            'path in URL' => ['base_url', 'http://ml.internal:8100/api'],
            'short token' => ['service_token', 'too-short'],
            'zero connect timeout' => ['connect_timeout_seconds', 0],
            'excessive connect timeout' => ['connect_timeout_seconds', 31],
            'total below connect' => ['timeout_seconds', 1],
            'excessive total timeout' => ['timeout_seconds', 121],
            'zero max jobs' => ['max_jobs_per_request', 0],
            'too many jobs' => ['max_jobs_per_request', 501],
            'zero max results' => ['max_results', 0],
            'too many results' => ['max_results', 101],
            'results exceed jobs' => ['max_results', 101],
            'missing version' => ['model_version', ''],
        ];
    }

    public function test_max_results_cannot_exceed_max_jobs(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['max_jobs_per_request'] = 2;
        $configuration['max_results'] = 3;

        $this->expectException(MlRecommendationConfigurationException::class);
        MlClientConfiguration::fromArray($configuration);
    }

    public function test_contract_resolves_with_current_configuration(): void
    {
        $client = $this->app->make(RecommendationMlClientContract::class);

        $this->assertInstanceOf(RecommendationMlClient::class, $client);
        $this->assertSame(
            $this->validConfiguration()['feature_schema_version'],
            $client->safeConfiguration()['feature_schema_version'],
        );
        $this->assertArrayNotHasKey('service_token', $client->safeConfiguration());
    }

    public function test_health_operations_use_exact_unauthenticated_endpoints(): void
    {
        Http::fake([
            'http://ml.internal:8100/health/live' => Http::response($this->livePayload()),
            'http://ml.internal:8100/health/ready' => Http::response($this->readyPayload()),
        ]);

        $this->assertInstanceOf(MlLivenessResult::class, $this->client()->live());
        $this->assertInstanceOf(MlReadinessResult::class, $this->client()->ready());

        Http::assertSent(function (Request $request): bool {
            return in_array($request->url(), [
                'http://ml.internal:8100/health/live',
                'http://ml.internal:8100/health/ready',
            ], true)
                && $request->method() === 'GET'
                && ! $request->hasHeader('X-ML-Service-Token')
                && ! $request->hasHeader('Authorization')
                && ! $request->hasHeader('Cookie');
        });
        Http::assertSentCount(2);
    }

    public function test_metadata_uses_exact_service_token_and_json_headers(): void
    {
        Http::fake([
            'http://ml.internal:8100/v1/model/metadata' => Http::response(
                $this->metadataPayload(),
            ),
        ]);

        $metadata = $this->client()->metadata();

        $this->assertInstanceOf(MlModelMetadata::class, $metadata);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://ml.internal:8100/v1/model/metadata'
                && $request->method() === 'GET'
                && $request->hasHeader('X-ML-Service-Token', self::TOKEN)
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && ! $request->hasHeader('Authorization')
                && ! $request->hasHeader('Cookie');
        });
    }

    public function test_rank_uses_exact_token_and_exact_deterministic_payload(): void
    {
        $request = $this->rankRequest();
        Http::fake([
            'http://ml.internal:8100/v1/recommendations/rank' => Http::response(
                $this->rankResponsePayload(),
            ),
        ]);

        $response = $this->client()->rank($request);

        $this->assertInstanceOf(MlRankResponse::class, $response);
        Http::assertSent(function (Request $sent) use ($request): bool {
            return $sent->url() === 'http://ml.internal:8100/v1/recommendations/rank'
                && $sent->method() === 'POST'
                && $sent->hasHeader('X-ML-Service-Token', self::TOKEN)
                && $sent->hasHeader('Accept', 'application/json')
                && $sent->hasHeader('Content-Type', 'application/json')
                && ! $sent->hasHeader('Authorization')
                && ! $sent->hasHeader('Cookie')
                && $sent->data() == $request->toArray();
        });
    }

    public function test_transport_options_apply_timeouts_disable_redirects_and_do_not_retry(): void
    {
        $method = new ReflectionMethod(RecommendationMlClient::class, 'pendingRequest');
        $pending = $method->invoke($this->client());
        $this->assertInstanceOf(PendingRequest::class, $pending);
        $this->assertSame(2, $pending->getOptions()['connect_timeout']);
        $this->assertSame(10, $pending->getOptions()['timeout']);
        $this->assertFalse($pending->getOptions()['allow_redirects']);

        $tries = new ReflectionProperty(PendingRequest::class, 'tries');
        $this->assertSame(1, $tries->getValue($pending));
        $this->assertArrayNotHasKey('cookies', $pending->getOptions());
        $this->assertArrayNotHasKey('auth', $pending->getOptions());
    }

    public function test_phase_12_request_is_represented_deterministically(): void
    {
        $fixture = $this->rankRequestPayload();
        $request = MlRankRequest::fromArray($fixture);

        $this->assertEquals($fixture, $request->toArray());
        $this->assertSame([121, 122, 123], $request->jobIds());
    }

    public function test_skill_normalization_and_duplicate_policy_match_python(): void
    {
        $payload = $this->rankRequestPayload();
        $payload['candidate']['professional_facts']['skills'] = [
            ['name' => ' CI — CD ', 'proficiency' => 1, 'years_experience' => 4],
            ['name' => 'ci-cd', 'proficiency' => 5, 'years_experience' => 2],
        ];
        $payload['jobs'][0]['professional_facts']['required_skills'] = [
            ['name' => ' PHP ', 'weight' => 2],
            ['name' => 'php', 'weight' => 5],
        ];
        $payload['jobs'][0]['professional_facts']['nice_to_have_skills'] = [
            'PHP',
            ' CI — CD ',
            'ci-cd',
        ];

        $request = MlRankRequest::fromArray($payload)->toArray();

        $this->assertSame([[
            'name' => 'ci-cd',
            'proficiency' => 5.0,
            'years_experience' => 4.0,
        ]], $request['candidate']['professional_facts']['skills']);
        $this->assertSame([[
            'name' => 'php',
            'weight' => 5.0,
        ]], $request['jobs'][0]['professional_facts']['required_skills']);
        $this->assertSame(
            ['ci-cd'],
            $request['jobs'][0]['professional_facts']['nice_to_have_skills'],
        );
    }

    #[DataProvider('invalidRequestProvider')]
    public function test_invalid_requests_fail_locally_without_http(callable $mutation): void
    {
        Http::fake();
        $payload = $this->rankRequestPayload();
        $mutation($payload);

        try {
            MlRankRequest::fromArray($payload)->assertForConfiguration(
                MlClientConfiguration::fromArray($this->validConfiguration()),
            );
            $this->fail('Expected request validation to fail.');
        } catch (MlRecommendationValidationException $exception) {
            $this->assertSame('rank', $exception->operation);
            $this->assertStringNotContainsString(
                'candidate',
                json_encode($exception->safeContext(), JSON_THROW_ON_ERROR),
            );
        }
        Http::assertSentCount(0);
    }

    public static function invalidRequestProvider(): array
    {
        return [
            'invalid UUID' => [fn (array &$v) => $v['request_id'] = 'invalid'],
            'empty jobs' => [fn (array &$v) => $v['jobs'] = []],
            'duplicate job IDs' => [fn (array &$v) => $v['jobs'][1]['job_id'] = 121],
            'non-positive job ID' => [fn (array &$v) => $v['jobs'][0]['job_id'] = 0],
            'zero limit' => [fn (array &$v) => $v['limit'] = 0],
            'limit above jobs' => [fn (array &$v) => $v['limit'] = 4],
            'unsupported schema' => [
                fn (array &$v) => $v['feature_schema_version'] = 'unsupported',
            ],
            'non-finite candidate number' => [
                fn (array &$v) => $v['candidate']['professional_facts']['total_experience_years'] = INF,
            ],
            'non-finite job number' => [
                fn (array &$v) => $v['jobs'][0]['professional_facts']['minimum_experience_years'] = NAN,
            ],
            'unknown candidate fact' => [
                fn (array &$v) => $v['candidate']['professional_facts']['unknown'] = true,
            ],
            'feature vector' => [
                fn (array &$v) => $v['candidate']['professional_facts']['features'] = [1, 2],
            ],
            'label' => [fn (array &$v) => $v['labels'] = [1]],
            'nested PII' => [
                fn (array &$v) => $v['jobs'][0]['professional_facts']['email']
                    = 'redacted@example.invalid',
            ],
            'email profile ref' => [
                fn (array &$v) => $v['candidate']['profile_ref']
                    = 'redacted@example.invalid',
            ],
            'phone profile ref' => [
                fn (array &$v) => $v['candidate']['profile_ref'] = '+000 000 0000',
            ],
        ];
    }

    public function test_configured_request_limits_are_enforced_before_http(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['max_jobs_per_request'] = 2;
        $configuration['max_results'] = 2;
        config()->set('recommendation_ml', $configuration);
        Http::fake();

        $this->expectException(MlRecommendationValidationException::class);
        try {
            $this->client()->rank($this->rankRequest());
        } finally {
            Http::assertSentCount(0);
        }
    }

    #[DataProvider('sensitiveKeyProvider')]
    public function test_privacy_guard_rejects_every_sensitive_key_case_insensitively(
        string $key,
    ): void {
        $guard = new MlOutboundPayloadGuard;
        $payload = ['candidate' => ['professional_facts' => [
            strtoupper(str_replace('_', '-', $key)) => 'never-expose-this-value',
        ]]];

        try {
            $guard->assertSafe($payload, '00000000-0000-4000-8000-000000000012');
            $this->fail('Expected privacy guard failure.');
        } catch (MlRecommendationValidationException $exception) {
            $serialized = json_encode($exception->safeContext(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('never-expose-this-value', $serialized);
            $this->assertSame('ML_SENSITIVE_FIELD_NOT_ALLOWED', $exception->internalCode);
        }
    }

    public static function sensitiveKeyProvider(): array
    {
        $keys = [
            'name',
            'full_name',
            'email',
            'phone',
            'birth_date',
            'date_of_birth',
            'age',
            'gender',
            'sex',
            'nationality',
            'marital_status',
            'personal_address',
            'address',
            'cv_file',
            'cv_path',
            'raw_cv',
            'raw_cv_text',
            'parsed_cv_json',
            'cover_letter',
            'screening_answers',
            'application_status',
            'application_history',
            'test_results',
            'interview_results',
            'internal_notes',
            'auth_token',
            'sanctum_token',
            'cookie',
            'cookies',
            'session',
            'password',
            'secret',
            'db_password',
            'database_url',
        ];

        return array_combine($keys, array_map(fn (string $key): array => [$key], $keys));
    }

    public function test_skill_name_is_allowed_only_in_approved_contexts(): void
    {
        $guard = new MlOutboundPayloadGuard;
        $guard->assertSafe([
            'candidate' => ['professional_facts' => ['skills' => [['name' => 'php']]]],
            'jobs' => [[
                'professional_facts' => [
                    'required_skills' => [['name' => 'php']],
                    'nice_to_have_skills' => ['testing'],
                ],
            ]],
        ]);
        $this->addToAssertionCount(1);

        $this->expectException(MlRecommendationValidationException::class);
        $guard->assertSafe(['jobs' => [['professional_facts' => ['name' => 'not-allowed']]]]);
    }

    public function test_valid_live_ready_metadata_and_rank_responses_are_parsed(): void
    {
        Http::fake([
            'http://ml.internal:8100/health/live' => Http::response($this->livePayload()),
            'http://ml.internal:8100/health/ready' => Http::response($this->readyPayload()),
            'http://ml.internal:8100/v1/model/metadata' => Http::response(
                $this->metadataPayload(),
            ),
            'http://ml.internal:8100/v1/recommendations/rank' => Http::response(
                $this->rankResponsePayload(),
            ),
        ]);

        $client = $this->client();
        $this->assertSame('live', $client->live()->status);
        $this->assertSame('ready', $client->ready()->status);
        $this->assertTrue($client->metadata()->ready);
        $this->assertCount(3, $client->rank($this->rankRequest())->predictions);
    }

    #[DataProvider('invalidRankResponseProvider')]
    public function test_invalid_rank_responses_are_rejected(callable $mutation): void
    {
        $payload = $this->rankResponsePayload();
        $mutation($payload);
        Http::fake([
            'http://ml.internal:8100/v1/recommendations/rank' => Http::response($payload),
        ]);

        $this->expectException(MlRecommendationContractException::class);
        $this->client()->rank($this->rankRequest());
    }

    public static function invalidRankResponseProvider(): array
    {
        return [
            'request ID mismatch' => [fn (array &$v) => $v['request_id']
                = '00000000-0000-4000-8000-000000000099'],
            'API contract mismatch' => [fn (array &$v) => $v['api_contract_version'] = 'bad'],
            'bundle mismatch' => [fn (array &$v) => $v['bundle_version'] = 'bad'],
            'model mismatch' => [fn (array &$v) => $v['model_version'] = 'bad'],
            'feature schema mismatch' => [fn (array &$v) => $v['feature_schema_version'] = 'bad'],
            'explanation contract mismatch' => [
                fn (array &$v) => $v['explanation_contract_version'] = 'bad',
            ],
            'score transform mismatch' => [
                fn (array &$v) => $v['score_transform_version'] = 'bad',
            ],
            'prediction count mismatch' => [fn (array &$v) => $v['prediction_count'] = 2],
            'requested limit mismatch' => [fn (array &$v) => $v['requested_limit'] = 2],
            'missing job' => [fn (array &$v) => $v['predictions'][0]['job_id'] = 999],
            'extra job' => [fn (array &$v) => $v['predictions'][1]['job_id'] = 999],
            'duplicate job' => [
                fn (array &$v) => $v['predictions'][1]['job_id']
                    = $v['predictions'][0]['job_id'],
            ],
            'rank gap' => [fn (array &$v) => $v['predictions'][2]['rank'] = 4],
            'duplicate rank' => [fn (array &$v) => $v['predictions'][1]['rank'] = 1],
            'non-finite raw score' => [fn (array &$v) => $v['predictions'][0]['raw_score'] = INF],
            'display score below range' => [
                fn (array &$v) => $v['predictions'][0]['display_score'] = -1,
            ],
            'display score above range' => [
                fn (array &$v) => $v['predictions'][0]['display_score'] = 101,
            ],
            'invalid factor code' => [
                fn (array &$v) => $v['predictions'][0]['top_positive_factors'][0]['code']
                    = 'RAW_FEATURE_CODE',
            ],
            'invalid factor group' => [
                fn (array &$v) => $v['predictions'][0]['top_positive_factors'][0]['feature_group'] = 'raw_feature',
            ],
            'invalid positive direction' => [
                fn (array &$v) => $v['predictions'][0]['top_positive_factors'][0]['direction'] = 'decreases_model_score',
            ],
            'invalid negative direction' => [
                fn (array &$v) => $v['predictions'][0]['top_negative_factors'][0]['direction'] = 'increases_model_score',
            ],
            'invalid factor strength' => [
                fn (array &$v) => $v['predictions'][0]['top_positive_factors'][0]['strength'] = 1.1,
            ],
            'non-finite contribution' => [
                fn (array &$v) => $v['predictions'][0]['top_positive_factors'][0]['contribution'] = NAN,
            ],
            'more than three factors' => [
                function (array &$v): void {
                    $factor = $v['predictions'][0]['top_positive_factors'][0];
                    $v['predictions'][0]['top_positive_factors'] = array_fill(0, 4, $factor);
                },
            ],
            'unexpected raw feature field' => [
                fn (array &$v) => $v['predictions'][0]['top_positive_factors'][0]['raw_feature'] = 'candidate_email',
            ],
            'unexpected feature value' => [
                fn (array &$v) => $v['predictions'][0]['top_positive_factors'][0]['feature_value'] = 1,
            ],
            'bad explanation note' => [fn (array &$v) => $v['explanation_note'] = 'Probability'],
            'negative latency' => [fn (array &$v) => $v['latency_ms'] = -1],
            'invalid ordering' => [
                fn (array &$v) => $v['predictions'][1]['raw_score']
                    = $v['predictions'][0]['raw_score'] + 1,
            ],
            'tie ordering by descending ID' => [
                function (array &$v): void {
                    $v['predictions'][0]['raw_score'] = 5;
                    $v['predictions'][1]['raw_score'] = 5;
                    $v['predictions'][0]['job_id'] = 123;
                    $v['predictions'][1]['job_id'] = 122;
                    $v['predictions'][2]['job_id'] = 121;
                },
            ],
            'unknown response field' => [fn (array &$v) => $v['raw_features'] = []],
        ];
    }

    #[DataProvider('invalidMetadataProvider')]
    public function test_invalid_metadata_versions_and_contract_are_rejected(
        string $key,
        mixed $value,
    ): void {
        $payload = $this->metadataPayload();
        $payload[$key] = $value;
        Http::fake([
            'http://ml.internal:8100/v1/model/metadata' => Http::response($payload),
        ]);

        $this->expectException(MlRecommendationContractException::class);
        $this->client()->metadata();
    }

    public static function invalidMetadataProvider(): array
    {
        return [
            'API contract' => ['api_contract_version', 'bad'],
            'bundle' => ['bundle_version', 'bad'],
            'model' => ['model_version', 'bad'],
            'feature schema' => ['feature_schema_version', 'bad'],
            'score transform' => ['score_transform_version', 'bad'],
            'explanation contract' => ['explanation_contract_version', 'bad'],
            'model hash' => ['model_sha256', 'not-a-hash'],
            'feature hash' => ['feature_schema_sha256', 'not-a-hash'],
            'not ready' => ['ready', false],
        ];
    }

    #[DataProvider('failureMappingProvider')]
    public function test_http_failures_map_to_typed_safe_exceptions(
        int $status,
        string $expectedClass,
        string $expectedCode,
    ): void {
        Http::fake([
            'http://ml.internal:8100/v1/model/metadata' => Http::response([
                'error' => [
                    'code' => 'REQUEST_VALIDATION_FAILED',
                    'secret' => self::TOKEN,
                ],
            ], $status),
        ]);

        try {
            $this->client()->metadata();
            $this->fail('Expected HTTP failure.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expectedClass, $exception);
            $this->assertSame($expectedCode, $exception->internalCode);
            $this->assertSame($status, $exception->httpStatus);
            $serialized = json_encode($exception->safeContext(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(self::TOKEN, $serialized);
            $this->assertStringNotContainsString('secret', $serialized);
            if ($status === 422) {
                $this->assertSame(
                    'REQUEST_VALIDATION_FAILED',
                    $exception->serviceErrorCode,
                );
            }
        }
    }

    public static function failureMappingProvider(): array
    {
        return [
            '401' => [
                401,
                MlRecommendationAuthenticationException::class,
                'ML_AUTHENTICATION_FAILED',
            ],
            '422' => [
                422,
                MlRecommendationValidationException::class,
                'ML_SERVICE_VALIDATION_FAILED',
            ],
            '429' => [
                429,
                MlRecommendationUnavailableException::class,
                'ML_SERVICE_RATE_LIMITED',
            ],
            '503' => [
                503,
                MlRecommendationUnavailableException::class,
                'ML_SERVICE_UNAVAILABLE',
            ],
            '500' => [
                500,
                MlRecommendationUnavailableException::class,
                'ML_SERVICE_UNAVAILABLE',
            ],
        ];
    }

    #[DataProvider('transportFailureProvider')]
    public function test_connection_and_timeout_failures_are_safe_transport_errors(
        string $transportDetail,
    ): void {
        Http::fake(fn () => throw new ConnectionException($transportDetail));

        try {
            $this->client()->metadata();
            $this->fail('Expected transport failure.');
        } catch (MlRecommendationTransportException $exception) {
            $this->assertSame('ML_TRANSPORT_FAILED', $exception->internalCode);
            $this->assertTrue($exception->retryable);
            $this->assertStringNotContainsString($transportDetail, $exception->getMessage());
            $this->assertStringNotContainsString(
                $transportDetail,
                json_encode($exception->safeContext(), JSON_THROW_ON_ERROR),
            );
        }
    }

    public static function transportFailureProvider(): array
    {
        return [
            'connection' => ['private connection detail'],
            'timeout' => ['private timeout detail'],
        ];
    }

    #[DataProvider('malformedBodyProvider')]
    public function test_malformed_successful_bodies_are_contract_failures(string $body): void
    {
        Http::fake([
            'http://ml.internal:8100/health/live' => Http::response(
                $body,
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->expectException(MlRecommendationContractException::class);
        $this->client()->live();
    }

    public static function malformedBodyProvider(): array
    {
        return [
            'invalid JSON' => ['{invalid'],
            'empty body' => [''],
            'scalar JSON' => ['true'],
        ];
    }

    public function test_client_never_logs_payload_token_or_raw_response(): void
    {
        Log::spy();
        Http::fake([
            'http://ml.internal:8100/v1/recommendations/rank' => Http::response(
                $this->rankResponsePayload(),
            ),
        ]);

        $this->client()->rank($this->rankRequest());

        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_all_predictions_are_retained_when_requested_limit_is_smaller(): void
    {
        $requestPayload = $this->rankRequestPayload();
        $requestPayload['limit'] = 1;
        $responsePayload = $this->rankResponsePayload();
        $responsePayload['requested_limit'] = 1;

        $response = MlRankResponse::fromArray(
            $responsePayload,
            MlRankRequest::fromArray($requestPayload),
            MlClientConfiguration::fromArray($this->validConfiguration()),
        );

        $this->assertSame(1, $response->requestedLimit);
        $this->assertCount(3, $response->predictions);
    }

    public function test_client_operations_issue_zero_database_queries_or_writes(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Http::fake([
            'http://ml.internal:8100/health/live' => Http::response($this->livePayload()),
            'http://ml.internal:8100/health/ready' => Http::response($this->readyPayload()),
            'http://ml.internal:8100/v1/model/metadata' => Http::response(
                $this->metadataPayload(),
            ),
            'http://ml.internal:8100/v1/recommendations/rank' => Http::response(
                $this->rankResponsePayload(),
            ),
        ]);

        $client = $this->client();
        $client->live();
        $client->ready();
        $client->metadata();
        $client->rank($this->rankRequest());

        $this->assertSame([], $queries);
        Http::assertSentCount(4);
    }

    private function client(): RecommendationMlClient
    {
        return $this->app->make(RecommendationMlClientContract::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validConfiguration(): array
    {
        return [
            'base_url' => 'http://ml.internal:8100',
            'service_token' => self::TOKEN,
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

    private function rankRequest(): MlRankRequest
    {
        return MlRankRequest::fromArray($this->rankRequestPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function rankRequestPayload(): array
    {
        return $this->jsonFixture('request.example.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function rankResponsePayload(): array
    {
        return $this->jsonFixture('response.example.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFixture(string $file): array
    {
        $path = base_path(
            'services/ml-recommendation/data/contracts/inference/v1/'.$file,
        );
        $decoded = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function livePayload(): array
    {
        return [
            'status' => 'live',
            'service' => 'ml-recommendation',
            'service_version' => '0.2.0',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function readyPayload(): array
    {
        return [
            'status' => 'ready',
            'service' => 'ml-recommendation',
            'service_version' => '0.2.0',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'feature_schema_version' => 'job-rec-features-v1',
        ];
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function metadataPayload(): array
    {
        return [
            'api_contract_version' => 'recommendation-ranking-api-v1',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'model_format' => 'xgboost-json-v1',
            'model_sha256' => str_repeat('a', 64),
            'dataset_version' => 'synthetic-job-rec-1.0.0',
            'feature_schema_version' => 'job-rec-features-v1',
            'feature_schema_sha256' => str_repeat('b', 64),
            'feature_count' => 103,
            'model_source_revision' => '6cd51f733d5197e0c3f6b7dfb3711c2860ffef71',
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'reason_code_mapping_version' => 'recommendation-reason-codes-v1',
            'ready' => true,
        ];
    }
}
