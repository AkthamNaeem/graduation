<?php

namespace Tests\Feature\Api\V1;

use App\Models\RecommendationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsRecommendationPersistenceScenarios;
use Tests\TestCase;

class RecommendationFailureMatrixTest extends TestCase
{
    use BuildsRecommendationPersistenceScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecommendationPersistenceScenario();
    }

    protected function tearDown(): void
    {
        $this->tearDownRecommendationPersistenceScenario();
        parent::tearDown();
    }

    #[DataProvider('networkFailureProvider')]
    public function test_recommendation_failure_matrix_uses_one_attempt_and_safe_fallback(
        string $failure,
        string $expectedCode,
    ): void {
        $user = $this->recommendationUser(
            ['phone' => '+222222222222'],
            [
                'name' => 'Private Failure Candidate',
                'email' => 'private-failure@example.test',
            ],
        );
        $company = $this->recommendationCompany();
        $this->recommendationJob($company);
        $this->recommendationJob($company);
        $providerCalls = 0;
        Log::spy();
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => function (Request $request) use (
                $failure,
                &$providerCalls,
            ) {
                $providerCalls++;

                return $this->failureResponse($failure, $request->data());
            },
        ]);

        $token = $user->createToken('phase17-failure')->plainTextToken;
        $response = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Recommended jobs retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.recommendation_engine', 'matching_v2_fallback')
            ->assertJsonPath('data.0.matching_score_version', '2.0')
            ->assertJsonPath('data.0.fallback_used', true)
            ->assertJsonMissingPath('data.0.safe_fallback_code');
        $this->assertSame(1, $providerCalls);
        Http::assertSentCount(1);

        $run = RecommendationRun::firstOrFail();
        $this->assertSame('matching_v2_fallback', $run->engine->value);
        $this->assertSame($expectedCode, $run->fallback_code);
        $this->assertTrue($run->fallback_used);
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 2);

        $public = $response->getContent();
        foreach ([
            self::ML_TOKEN,
            $token,
            'private-failure@example.test',
            '+222222222222',
            'Private Failure Candidate',
            self::ML_URL,
            'SAFE_PROVIDER_ERROR',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $public);
        }
        $this->assertStringNotContainsString('ML_', $public);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            function (string $event, array $context) use ($expectedCode): bool {
                $this->assertSame('recommendation_ml_fallback', $event);
                $this->assertSame($expectedCode, $context['fallback_code']);
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString(self::ML_TOKEN, $encoded);
                $this->assertStringNotContainsString('private-failure@example.test', $encoded);
                $this->assertStringNotContainsString('+222222222222', $encoded);

                return true;
            },
        );
    }

    public static function networkFailureProvider(): array
    {
        return [
            'connection refused' => ['connection_refused', 'ML_TRANSPORT_FAILURE'],
            'read timeout' => ['timeout', 'ML_TRANSPORT_FAILURE'],
            'HTTP 401' => ['401', 'ML_AUTHENTICATION_FAILURE'],
            'HTTP 422' => ['422', 'ML_PROVIDER_VALIDATION_FAILURE'],
            'HTTP 429' => ['429', 'ML_RATE_LIMITED'],
            'HTTP 500' => ['500', 'ML_MODEL_UNAVAILABLE'],
            'HTTP 503' => ['503', 'ML_MODEL_UNAVAILABLE'],
            'empty body' => ['empty', 'ML_CONTRACT_FAILURE'],
            'invalid JSON' => ['invalid_json', 'ML_CONTRACT_FAILURE'],
            'version mismatch' => ['version_mismatch', 'ML_CONTRACT_FAILURE'],
            'request ID mismatch' => ['request_id_mismatch', 'ML_CONTRACT_FAILURE'],
            'missing prediction' => ['missing_prediction', 'ML_CONTRACT_FAILURE'],
            'extra prediction' => ['extra_prediction', 'ML_CONTRACT_FAILURE'],
            'duplicate Job ID' => ['duplicate_job', 'ML_CONTRACT_FAILURE'],
            'rank gap' => ['rank_gap', 'ML_CONTRACT_FAILURE'],
            'invalid score' => ['invalid_score', 'ML_CONTRACT_FAILURE'],
            'invalid reason code' => ['invalid_reason', 'ML_CONTRACT_FAILURE'],
            'abrupt close' => ['abrupt_close', 'ML_TRANSPORT_FAILURE'],
        ];
    }

    private function failureResponse(string $failure, array $payload): mixed
    {
        if (in_array($failure, [
            'connection_refused',
            'timeout',
            'abrupt_close',
        ], true)) {
            return Http::failedConnection();
        }
        if (ctype_digit($failure)) {
            return Http::response(
                ['code' => 'SAFE_PROVIDER_ERROR', 'private' => 'never-public'],
                (int) $failure,
            );
        }
        if ($failure === 'empty') {
            return Http::response('', 200, ['Content-Type' => 'application/json']);
        }
        if ($failure === 'invalid_json') {
            return Http::response('{invalid', 200, ['Content-Type' => 'application/json']);
        }

        $response = $this->validRecommendationRankResponse($payload);
        match ($failure) {
            'version_mismatch' => $response['model_version'] = 'unexpected-model',
            'request_id_mismatch' => $response['request_id']
                = '00000000-0000-4000-8000-000000000099',
            'missing_prediction' => array_pop($response['predictions']),
            'extra_prediction' => $response['predictions'][] = [
                ...$response['predictions'][0],
                'job_id' => 999999,
                'rank' => 3,
            ],
            'duplicate_job' => $response['predictions'][1]['job_id']
                = $response['predictions'][0]['job_id'],
            'rank_gap' => $response['predictions'][1]['rank'] = 3,
            'invalid_score' => $response['predictions'][0]['display_score'] = 101,
            'invalid_reason' => $response['predictions'][0]['top_positive_factors'][0]['code']
                = 'RAW_PRIVATE_FEATURE',
            default => null,
        };
        if ($failure === 'missing_prediction') {
            $response['prediction_count'] = count($response['predictions']);
        }
        if ($failure === 'extra_prediction') {
            $response['prediction_count'] = count($response['predictions']);
        }

        return Http::response($response);
    }
}
