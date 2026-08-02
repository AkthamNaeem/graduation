<?php

namespace Tests\Unit;

use App\Contracts\CVSummary\CVSummaryClient;
use App\Exceptions\CVSummaryGenerationException;
use App\Services\CVSummary\GroqCVSummaryClient;
use App\Services\CVSummary\OpenAICVSummaryClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroqCVSummaryClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cv_summary.provider' => 'groq',
            'cv_summary.groq.api_key' => 'groq-test-key',
            'cv_summary.groq.model' => 'openai/gpt-oss-20b',
            'cv_summary.groq.max_completion_tokens' => 2048,
            'cv_summary.groq.reasoning_effort' => 'low',
            'cv_summary.groq.temperature' => 0.2,
        ]);
    }

    public function test_container_selects_only_supported_summary_clients(): void
    {
        config()->set('cv_summary.provider', 'openai');
        $this->assertInstanceOf(OpenAICVSummaryClient::class, $this->app->make(CVSummaryClient::class));

        config()->set('cv_summary.provider', 'groq');
        $this->assertInstanceOf(GroqCVSummaryClient::class, $this->app->make(CVSummaryClient::class));

        config()->set('cv_summary.provider', 'unsupported');

        try {
            $this->app->make(CVSummaryClient::class);
            $this->fail('Expected unsupported provider failure.');
        } catch (CVSummaryGenerationException $exception) {
            $this->assertSame('CV_SUMMARY_INVALID_PROVIDER', $exception->errorCode);
            $this->assertSame(500, $exception->status);
        }
    }

    public function test_it_sends_private_strict_chat_completion_request_and_parses_content(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload(), 200)]);

        $result = $this->client()->generate($this->source(), 'en');

        $this->assertSame($this->summary(), $result['data']);
        $this->assertSame('chatcmpl_cv_summary', $result['request_id']);
        $this->assertSame('groq', $this->client()->provider());
        $this->assertSame('openai/gpt-oss-20b', $this->client()->model());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://api.groq.com/openai/v1/chat/completions', $request->url());
            $this->assertSame('Bearer groq-test-key', $request->header('Authorization')[0] ?? null);
            $this->assertSame('openai/gpt-oss-20b', $request['model']);
            $this->assertSame(2048, $request['max_completion_tokens']);
            $this->assertSame('low', $request['reasoning_effort']);
            $this->assertFalse($request['include_reasoning']);
            $this->assertSame(0.2, $request['temperature']);
            $this->assertFalse($request['stream']);
            $this->assertSame('json_schema', $request['response_format']['type']);
            $this->assertSame('application_cv_summary', $request['response_format']['json_schema']['name']);
            $this->assertTrue($request['response_format']['json_schema']['strict']);
            $this->assertSame(
                ['headline', 'summary', 'strengths', 'gaps', 'evidence'],
                $request['response_format']['json_schema']['schema']['required'],
            );
            $this->assertArrayNotHasKey('store', $request->data());

            return true;
        });
    }

    public function test_invalid_generation_settings_use_safe_defaults(): void
    {
        config([
            'cv_summary.groq.max_completion_tokens' => 100,
            'cv_summary.groq.reasoning_effort' => 'extreme',
            'cv_summary.groq.temperature' => 3,
        ]);
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload(), 200)]);

        $this->client()->generate($this->source(), 'en');

        Http::assertSent(fn (Request $request): bool => $request['max_completion_tokens'] === 2048
            && $request['reasoning_effort'] === 'low'
            && $request['temperature'] === 0.2);
    }

    public function test_json_validate_failure_retries_once_with_json_object_and_local_validation(): void
    {
        Log::spy();
        Http::fakeSequence()
            ->push($this->jsonValidationFailure(), 400)
            ->push($this->responsePayload(), 200);

        $result = $this->client()->generate($this->source(), 'ar');

        $this->assertSame($this->summary(), $result['data']);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame('json_schema', $requests[0][0]['response_format']['type']);
        $this->assertSame('json_object', $requests[1][0]['response_format']['type']);
        $this->assertArrayNotHasKey('json_schema', $requests[1][0]['response_format']);
        $this->assertStringContainsString('all of these keys', $requests[1][0]['messages'][0]['content']);
        $this->assertStringContainsString('Return the output in Arabic', $requests[1][0]['messages'][0]['content']);
        Log::shouldHaveReceived('warning')->once()->with(
            'Groq CV summary strict structured output failed; trying JSON object fallback.',
            [
                'provider' => 'groq',
                'http_status' => 400,
                'error_type' => 'invalid_request_error',
                'error_code' => 'json_validate_failed',
                'structured_output_mode' => 'json_schema',
                'model' => 'openai/gpt-oss-20b',
            ],
        );
    }

    #[DataProvider('invalidContentProvider')]
    public function test_invalid_content_is_rejected_without_retry(array $payload): void
    {
        Http::fake(['api.groq.com/*' => Http::response($payload, 200)]);

        $this->assertClientFailure('CV_SUMMARY_INVALID_RESPONSE', 502);
        Http::assertSentCount(1);
    }

    public static function invalidContentProvider(): array
    {
        return [
            'missing choices' => [[]],
            'empty content' => [[
                'choices' => [['message' => ['content' => '  ']]],
            ]],
            'invalid JSON' => [[
                'choices' => [['message' => ['content' => '{bad']]],
            ]],
            'contract mismatch' => [[
                'choices' => [['message' => ['content' => '{}']]],
            ]],
            'refusal' => [[
                'choices' => [['message' => ['content' => '{}', 'refusal' => 'No']]],
            ]],
        ];
    }

    #[DataProvider('terminalFailureProvider')]
    public function test_terminal_failures_use_stable_codes(int $status, string $code, int $attempts): void
    {
        Http::fake(['api.groq.com/*' => Http::response([], $status)]);

        $this->assertClientFailure($code, 503);
        Http::assertSentCount($attempts);
    }

    public static function terminalFailureProvider(): array
    {
        return [
            'unauthorized' => [401, 'CV_SUMMARY_AUTHENTICATION_FAILED', 1],
            'forbidden' => [403, 'CV_SUMMARY_AUTHENTICATION_FAILED', 1],
            'rate limited' => [429, 'CV_SUMMARY_RATE_LIMITED', 3],
            'provider unavailable' => [500, 'CV_SUMMARY_PROVIDER_UNAVAILABLE', 3],
        ];
    }

    public function test_missing_key_fails_without_an_http_request(): void
    {
        config()->set('cv_summary.groq.api_key', '');
        Http::fake();

        $this->assertClientFailure('CV_SUMMARY_AUTHENTICATION_FAILED', 503);
        Http::assertNothingSent();
    }

    public function test_connection_failure_is_a_safe_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('PRIVATE_TRANSPORT_DETAILS'));

        $this->assertClientFailure('CV_SUMMARY_TIMEOUT', 503);
    }

    #[DataProvider('fallbackFailureProvider')]
    public function test_json_object_fallback_failure_never_makes_a_third_request(array $secondPayload, int $status = 200): void
    {
        Http::fakeSequence()
            ->push($this->jsonValidationFailure(), 400)
            ->push($secondPayload, $status);

        $this->assertClientFailure('CV_SUMMARY_INVALID_RESPONSE', 502);
        Http::assertSentCount(2);
    }

    public static function fallbackFailureProvider(): array
    {
        return [
            'second validation failure' => [[
                'error' => [
                    'message' => 'PRIVATE_PROVIDER_BODY',
                    'type' => 'invalid_request_error',
                    'code' => 'json_validate_failed',
                ],
            ], 400],
            'empty content' => [[
                'choices' => [['message' => ['content' => '']]],
            ]],
            'invalid JSON' => [[
                'choices' => [['message' => ['content' => '{bad']]],
            ]],
            'contract mismatch' => [[
                'choices' => [['message' => ['content' => '{}']]],
            ]],
        ];
    }

    public function test_non_fallback_bad_request_logs_only_safe_identifiers(): void
    {
        Log::spy();
        Http::fake(['api.groq.com/*' => Http::response([
            'error' => [
                'message' => 'PRIVATE_PROVIDER_BODY',
                'type' => 'invalid request PRIVATE_VALUE',
                'code' => 'schema_invalid',
            ],
        ], 400)]);

        $this->assertClientFailure('CV_SUMMARY_INVALID_RESPONSE', 502);
        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->once()->with(
            'Groq CV summary request was rejected.',
            [
                'provider' => 'groq',
                'http_status' => 400,
                'error_type' => null,
                'error_code' => 'schema_invalid',
                'structured_output_mode' => 'json_schema',
                'model' => 'openai/gpt-oss-20b',
            ],
        );
    }

    private function assertClientFailure(string $code, int $status): void
    {
        try {
            $this->client()->generate($this->source(), 'en');
            $this->fail('Expected CV summary client failure.');
        } catch (CVSummaryGenerationException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('groq-test-key', $exception->getMessage());
        }
    }

    private function client(): GroqCVSummaryClient
    {
        return $this->app->make(GroqCVSummaryClient::class);
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'job' => ['title' => 'Backend Developer'],
            'verified_profile' => ['skills' => ['Laravel']],
            'selected_cv' => ['summary' => 'Builds APIs'],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return [
            'headline' => 'Backend candidate aligned with Laravel API work',
            'summary' => 'The candidate has explicit Laravel REST API experience relevant to the role.',
            'strengths' => ['Laravel REST API experience is explicitly evidenced.'],
            'gaps' => ['Docker experience is not evidenced in the supplied data.'],
            'evidence' => [[
                'statement' => 'Laravel REST API experience',
                'source' => 'Selected CV summary and verified profile',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function responsePayload(): array
    {
        return [
            'id' => 'chatcmpl_cv_summary',
            'choices' => [[
                'message' => [
                    'content' => json_encode($this->summary(), JSON_THROW_ON_ERROR),
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function jsonValidationFailure(): array
    {
        return [
            'error' => [
                'message' => 'PRIVATE_PROVIDER_BODY',
                'type' => 'invalid_request_error',
                'code' => 'json_validate_failed',
            ],
        ];
    }
}
