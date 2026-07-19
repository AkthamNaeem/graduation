<?php

namespace Tests\Unit;

use App\Exceptions\CVParserException;
use App\Models\Skill;
use App\Services\CV\CVParsedDataNormalizer;
use App\Services\CV\GroqCVTextParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroqCVTextParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cv.groq.api_key', 'groq-test-key');
        config()->set('cv.groq.model', 'configured-groq-model');
        config()->set('cv.parser.fallback_to_rules', false);
    }

    public function test_it_sends_a_strict_structured_chat_completions_request(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload($this->validParsed()), 200)]);

        $parsed = $this->parser()->parse('Laravel Developer at FutureX');

        $this->assertSame('groq', $parsed['_meta']['parser_driver']);
        $this->assertSame('configured-groq-model', $parsed['_meta']['model']);
        $this->assertFalse($parsed['_meta']['fallback_used']);
        $this->assertSame('json_schema_strict', $parsed['_meta']['structured_output_mode']);
        $this->assertArrayNotHasKey('structured_output_fallback_reason', $parsed['_meta']);
        $this->assertSame('1.0', $parsed['_meta']['schema_version']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer groq-test-key')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request['model'] === 'configured-groq-model'
                && $request['max_completion_tokens'] === 4096
                && $request['reasoning_effort'] === 'low'
                && $request['include_reasoning'] === false
                && $request['temperature'] === 0.5
                && $request['stream'] === false
                && ! isset($request['reasoning_format'])
                && str_contains($request['messages'][0]['content'], 'When day, month, and year are explicitly available, return YYYY-MM-DD.')
                && str_contains($request['messages'][0]['content'], 'Return YYYY-MM when month and year are available.')
                && str_contains($request['messages'][0]['content'], 'Extract every distinct experience entry')
                && str_contains($request['messages'][0]['content'], 'Concurrent or overlapping jobs are valid')
                && str_contains($request['messages'][0]['content'], 'Freelance is a valid company_name')
                && str_contains($request['messages'][0]['content'], 'Extract every education entry')
                && $request['messages'][1]['content'] === 'Laravel Developer at FutureX'
                && $request['response_format']['type'] === 'json_schema'
                && $request['response_format']['json_schema']['strict'] === true
                && $request['response_format']['json_schema']['schema']['properties']['birth_date']['description'] === 'Complete birth date in YYYY-MM-DD format, or null when incomplete.'
                && ! array_key_exists('pattern', $request['response_format']['json_schema']['schema']['properties']['birth_date'])
                && $request['response_format']['json_schema']['schema']['additionalProperties'] === false;
        });
    }

    public function test_regression_fixture_produces_safe_experience_education_and_skills(): void
    {
        $rawText = <<<'TEXT'
PERSONAL INFORMATION
Birth Date: 21 April 2002

EXPERIENCE
October 2024 - December 2025
Software Developer
Opti Tech | UAE (remote)

EDUCATION
2020 - 2026 (Expected)
Bachelor's degree, Information Technology
Damascus University

SKILLS
Laravel
MySQL
TEXT;
        $data = $this->validParsed();
        $data['birth_date'] = '21 April 2002';
        $data['experience'] = [[
            'title' => 'Software Developer', 'company_name' => 'Opti Tech', 'location' => 'UAE',
            'work_mode' => 'remote', 'start_date' => '2024-10', 'end_date' => '2025-12',
            'is_current' => false, 'description' => null, 'responsibilities' => [],
            'evidence' => "October 2024 - December 2025\nSoftware Developer\nOpti Tech | UAE (remote)",
            'confidence_score' => 0.98,
        ]];
        $data['education'] = [[
            'degree' => "Bachelor's degree", 'field_of_study' => 'Information Technology',
            'institution' => 'Damascus University', 'start_year' => 2020, 'graduation_year' => 2026,
            'is_expected' => true, 'description' => null,
            'evidence' => "2020 - 2026 (Expected)\nBachelor's degree, Information Technology\nDamascus University",
            'confidence_score' => 0.98,
        ]];
        $data['skills'] = ['Laravel', 'MySQL'];
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload($data), 200)]);

        $parsed = (new CVParsedDataNormalizer)->normalize($this->parser()->parse($rawText), $rawText);

        $this->assertSame('2002-04-21', $parsed['birth_date']);
        $this->assertSame('Software Developer', $parsed['experience'][0]['title']);
        $this->assertSame('Opti Tech', $parsed['experience'][0]['company_name']);
        $this->assertSame("Bachelor's degree", $parsed['education'][0]['degree']);
        $this->assertSame('Information Technology', $parsed['education'][0]['field_of_study']);
        $this->assertSame(['Laravel', 'MySQL'], $parsed['skills']);
    }

    public function test_synthetic_full_cv_keeps_three_overlapping_experiences_and_education_after_normalization(): void
    {
        $rawText = <<<'TEXT'
PERSONAL INFORMATION
Birth Date: 21 April 2002

EXPERIENCE
January 2026 - Present
Laravel Developer
Nova Systems
- Build APIs

October 2024 - December 2025
Software Developer
Orbit Labs
- Maintain services

January 2025 - Present
Web Developer
Freelance
- CMS customization & plugin development

EDUCATION
Bachelor's degree
Information Technology
Riverside University
2020-2026 Expected

SKILLS
React, React Native, Expo
react

LANGUAGES
Arabic: Native
English: Intermediate
TEXT;
        $data = $this->validParsed();
        $data['birth_date'] = '21 April 2002';
        $data['experience'] = [
            $this->experience('Laravel Developer', 'Nova Systems', '2026-01', null, true, 'Build APIs'),
            $this->experience('Software Developer', 'Orbit Labs', '2024-10', '2025-12', false, 'Maintain services'),
            $this->experience('Web Developer', 'Freelance', '2025-01', null, true, 'CMS customization & plugin development'),
        ];
        $data['education'] = [[
            'degree' => "Bachelor's degree", 'field_of_study' => 'Information Technology',
            'institution' => 'Riverside University', 'start_year' => 2020, 'graduation_year' => 2026,
            'is_expected' => true, 'description' => null,
            'evidence' => "Bachelor's degree Information Technology Riverside University 2020-2026 Expected",
            'confidence_score' => 1,
        ]];
        $data['skills'] = ['React, React Native, Expo', 'react'];
        $data['languages'] = [['name' => 'Arabic', 'level' => 'Native'], ['name' => 'English', 'level' => 'Intermediate']];
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload($data), 200)]);

        $parsed = (new CVParsedDataNormalizer)->normalize($this->parser()->parse($rawText), $rawText);

        $this->assertSame('2002-04-21', $parsed['birth_date']);
        $this->assertSame(['Nova Systems', 'Orbit Labs', 'Freelance'], array_column($parsed['experience'], 'company_name'));
        $this->assertCount(1, $parsed['education']);
        $this->assertCount(2, $parsed['languages']);
        $this->assertSame(['React', 'React Native', 'Expo'], $parsed['skills']);
        $this->assertSame(3, $parsed['_meta']['normalization']['output_counts']['experience']);
        $this->assertSame(1, $parsed['_meta']['normalization']['output_counts']['education']);
        $this->assertSame(0, array_sum($parsed['_meta']['normalization']['dropped_counts']));
    }

    public function test_json_validate_failed_retries_once_with_json_object_mode(): void
    {
        $rawText = 'PRIVATE_CV_MARKER Birth Date: 21 April 2002';
        Log::spy();
        Http::fakeSequence()
            ->push($this->jsonValidationFailure(), 400)
            ->push($this->responsePayload($this->validParsed()), 200);

        $parsed = $this->parser()->parse($rawText);

        $this->assertSame('groq', $parsed['_meta']['parser_driver']);
        $this->assertFalse($parsed['_meta']['fallback_used']);
        $this->assertSame('json_object_fallback', $parsed['_meta']['structured_output_mode']);
        $this->assertSame('json_validate_failed', $parsed['_meta']['structured_output_fallback_reason']);
        Http::assertSentCount(2);

        $requests = Http::recorded()->map(fn (array $record): Request => $record[0])->values();
        $this->assertSame('json_schema', $requests[0]['response_format']['type']);
        $this->assertSame(['type' => 'json_object'], $requests[1]['response_format']);
        $this->assertSame('configured-groq-model', $requests[1]['model']);
        $this->assertSame(4096, $requests[1]['max_completion_tokens']);
        $this->assertSame('low', $requests[1]['reasoning_effort']);
        $this->assertFalse($requests[1]['include_reasoning']);
        $this->assertSame(0.5, $requests[1]['temperature']);
        $this->assertFalse($requests[1]['stream']);
        $this->assertSame($rawText, $requests[1]['messages'][1]['content']);
        $this->assertStringContainsString('Return one valid JSON object only.', $requests[1]['messages'][0]['content']);
        $this->assertStringContainsString('Every experience and education item must include all contract fields.', $requests[1]['messages'][0]['content']);
        $this->assertStringContainsString('Extract every distinct experience entry', $requests[1]['messages'][0]['content']);
        $this->assertStringContainsString('Concurrent or overlapping jobs are valid', $requests[1]['messages'][0]['content']);
        $this->assertStringContainsString('Freelance is a valid company_name', $requests[1]['messages'][0]['content']);
        $this->assertStringContainsString('Extract every education entry', $requests[1]['messages'][0]['content']);
        $this->assertStringNotContainsString('supplied JSON schema', $requests[1]['messages'][0]['content']);
        $this->assertStringContainsString('Do not output markdown or code fences.', $requests[1]['messages'][0]['content']);
        $this->assertStringEndsWith('Keep each description and responsibility concise.', $requests[1]['messages'][0]['content']);

        Log::shouldHaveReceived('warning')->once()->with(
            'Groq strict structured output failed; trying JSON object fallback.',
            [
                'http_status' => 400,
                'error_type' => 'invalid_request_error',
                'error_code' => 'json_validate_failed',
                'structured_output_mode' => 'json_schema_strict',
                'max_completion_tokens' => 4096,
                'reasoning_effort' => 'low',
            ],
        );
    }

    public function test_custom_generation_settings_are_sent_to_groq(): void
    {
        config()->set('cv.groq.max_completion_tokens', 12000);
        config()->set('cv.groq.reasoning_effort', 'high');
        config()->set('cv.groq.temperature', 1.25);
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload($this->validParsed()), 200)]);

        $this->parser()->parse('CV');

        Http::assertSent(fn (Request $request): bool => $request['max_completion_tokens'] === 12000
            && $request['reasoning_effort'] === 'high'
            && $request['temperature'] === 1.25
            && $request['include_reasoning'] === false
            && $request['stream'] === false);
    }

    public function test_invalid_generation_settings_use_safe_defaults(): void
    {
        config()->set('cv.groq.max_completion_tokens', 1000);
        config()->set('cv.groq.reasoning_effort', 'extreme');
        config()->set('cv.groq.temperature', 2.1);
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload($this->validParsed()), 200)]);

        $this->parser()->parse('CV');

        Http::assertSent(fn (Request $request): bool => $request['max_completion_tokens'] === 4096
            && $request['reasoning_effort'] === 'low'
            && $request['temperature'] === 0.5);
    }

    public function test_json_object_json_validate_failed_has_a_specific_code_and_no_third_request(): void
    {
        $rawText = 'PRIVATE_CV_MARKER';
        Log::spy();
        Http::fakeSequence()
            ->push($this->jsonValidationFailure(), 400)
            ->push($this->jsonValidationFailure(), 400);

        try {
            $this->parser()->parse($rawText);
            $this->fail('Expected JSON generation failure.');
        } catch (CVParserException $exception) {
            $this->assertSame('GROQ_JSON_GENERATION_FAILED', $exception->reasonCode);
            $this->assertStringNotContainsString($rawText, $exception->getMessage());
            $this->assertStringNotContainsString('groq-test-key', $exception->getMessage());
        }

        Http::assertSentCount(2);
        Log::shouldHaveReceived('warning')->with(
            'Groq CV parser request was rejected.',
            [
                'http_status' => 400,
                'error_type' => 'invalid_request_error',
                'error_code' => 'json_validate_failed',
                'structured_output_mode' => 'json_object_fallback',
                'max_completion_tokens' => 4096,
                'reasoning_effort' => 'low',
            ],
        );
    }

    public function test_json_generation_failure_uses_rules_only_when_rules_fallback_is_enabled(): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        Http::fakeSequence()
            ->push($this->jsonValidationFailure(), 400)
            ->push($this->jsonValidationFailure(), 400);

        $parsed = $this->parser()->parse("Skills\nLaravel");

        $this->assertSame(['Laravel'], $parsed['skills']);
        $this->assertSame('rules', $parsed['_meta']['parser_driver']);
        $this->assertSame('groq', $parsed['_meta']['requested_driver']);
        $this->assertSame('GROQ_JSON_GENERATION_FAILED', $parsed['_meta']['fallback_reason']);
        Http::assertSentCount(2);
    }

    #[DataProvider('jsonObjectFailureProvider')]
    public function test_json_object_fallback_failures_are_diagnostic_and_never_make_a_third_request(string $content, string $reasonCode): void
    {
        Http::fakeSequence()
            ->push($this->jsonValidationFailure(), 400)
            ->push(['choices' => [['message' => ['content' => $content]]]], 200);

        try {
            $this->parser()->parse('PRIVATE_CV_MARKER');
            $this->fail('Expected JSON object fallback failure.');
        } catch (CVParserException $exception) {
            $this->assertSame($reasonCode, $exception->reasonCode);
            $this->assertStringNotContainsString('PRIVATE_CV_MARKER', $exception->getMessage());
            $this->assertStringNotContainsString('groq-test-key', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public static function jsonObjectFailureProvider(): array
    {
        return [
            'empty content' => ['  ', 'GROQ_EMPTY_CONTENT'],
            'invalid JSON' => ['{bad', 'GROQ_INVALID_JSON'],
            'contract mismatch' => ['{}', 'GROQ_CONTRACT_MISMATCH'],
        ];
    }

    public function test_missing_key_fails_without_request(): void
    {
        config()->set('cv.groq.api_key', '');
        Http::fake();

        try {
            $this->parser()->parse('CV');
            $this->fail('Expected authentication failure.');
        } catch (CVParserException $exception) {
            $this->assertSame('GROQ_AUTHENTICATION_FAILED', $exception->reasonCode);
            Http::assertNothingSent();
        }
    }

    public function test_request_too_large_has_a_safe_code_log_and_is_not_retried(): void
    {
        $rawText = 'PRIVATE_CV_MARKER';
        Log::spy();
        Http::fake(['api.groq.com/*' => Http::response([
            'error' => [
                'message' => 'PRIVATE_PROVIDER_BODY',
                'type' => 'rate_limit_exceeded',
                'code' => 'request_too_large',
            ],
        ], 413)]);

        try {
            $this->parser()->parse($rawText);
            $this->fail('Expected request-too-large failure.');
        } catch (CVParserException $exception) {
            $this->assertSame('GROQ_REQUEST_TOO_LARGE', $exception->reasonCode);
            $this->assertNotSame('GROQ_BAD_REQUEST', $exception->reasonCode);
            $this->assertStringNotContainsString($rawText, $exception->getMessage());
            $this->assertStringNotContainsString('groq-test-key', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->once()->with(
            'Groq CV parser request exceeded provider token capacity.',
            [
                'http_status' => 413,
                'error_type' => 'rate_limit_exceeded',
                'error_code' => 'request_too_large',
                'structured_output_mode' => 'json_schema_strict',
                'max_completion_tokens' => 4096,
                'reasoning_effort' => 'low',
            ],
        );
    }

    public function test_request_too_large_uses_rules_only_when_fallback_is_enabled(): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        Http::fake(['api.groq.com/*' => Http::response([], 413)]);

        $parsed = $this->parser()->parse("Skills\nLaravel");

        $this->assertSame(['Laravel'], $parsed['skills']);
        $this->assertSame('rules', $parsed['_meta']['parser_driver']);
        $this->assertSame('GROQ_REQUEST_TOO_LARGE', $parsed['_meta']['fallback_reason']);
        Http::assertSentCount(1);
    }

    #[DataProvider('terminalFailureProvider')]
    public function test_terminal_http_failures_have_safe_codes(int $status, string $code, int $attempts): void
    {
        Http::fake(['api.groq.com/*' => Http::response([], $status)]);

        try {
            $this->parser()->parse('CV');
            $this->fail('Expected parser failure.');
        } catch (CVParserException $exception) {
            $this->assertSame($code, $exception->reasonCode);
            Http::assertSentCount($attempts);
        }
    }

    public static function terminalFailureProvider(): array
    {
        return [
            'unauthorized' => [401, 'GROQ_AUTHENTICATION_FAILED', 1],
            'forbidden' => [403, 'GROQ_AUTHENTICATION_FAILED', 1],
            'rate limited' => [429, 'GROQ_RATE_LIMITED', 3],
            'server error' => [500, 'GROQ_UNAVAILABLE', 3],
        ];
    }

    public function test_connection_failure_is_a_safe_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('transport details'));

        $this->expectExceptionObject(new CVParserException('GROQ_TIMEOUT'));
        $this->parser()->parse('CV');
    }

    #[DataProvider('invalidResponseProvider')]
    public function test_invalid_responses_are_rejected_with_diagnostic_codes(array $payload, string $reasonCode): void
    {
        Http::fake(['api.groq.com/*' => Http::response($payload, 200)]);

        $this->expectExceptionObject(new CVParserException($reasonCode));
        $this->parser()->parse('CV');
    }

    public static function invalidResponseProvider(): array
    {
        return [
            'invalid json' => [
                ['choices' => [['message' => ['content' => '{bad']]]],
                'GROQ_INVALID_JSON',
            ],
            'missing choices' => [[], 'GROQ_EMPTY_CONTENT'],
            'empty content' => [
                ['choices' => [['message' => ['content' => '  ']]]],
                'GROQ_EMPTY_CONTENT',
            ],
            'invalid schema' => [
                ['choices' => [['message' => ['content' => '{}']]]],
                'GROQ_CONTRACT_MISMATCH',
            ],
            'refusal' => [
                ['choices' => [['message' => ['content' => '{}', 'refusal' => 'No']]]],
                'GROQ_REFUSAL',
            ],
        ];
    }

    #[DataProvider('fallbackFailureProvider')]
    public function test_operational_failures_can_fallback_to_rules(string $code, int $status): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        Http::fake(['api.groq.com/*' => Http::response([], $status)]);

        $parsed = $this->parser()->parse("Skills\nLaravel");

        $this->assertSame(['Laravel'], $parsed['skills']);
        $this->assertSame('rules', $parsed['_meta']['parser_driver']);
        $this->assertSame('groq', $parsed['_meta']['requested_driver']);
        $this->assertTrue($parsed['_meta']['fallback_used']);
        $this->assertSame($code, $parsed['_meta']['fallback_reason']);
    }

    public static function fallbackFailureProvider(): array
    {
        return [
            'rate limited' => ['GROQ_RATE_LIMITED', 429],
            'unavailable' => ['GROQ_UNAVAILABLE', 500],
        ];
    }

    public function test_contract_mismatch_can_fallback_to_rules(): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        Http::fakeSequence()
            ->push($this->jsonValidationFailure(), 400)
            ->push($this->responsePayload([]), 200);

        $parsed = $this->parser()->parse("Skills\nLaravel");

        $this->assertSame(['Laravel'], $parsed['skills']);
        $this->assertSame('GROQ_CONTRACT_MISMATCH', $parsed['_meta']['fallback_reason']);
        $this->assertSame('groq', $parsed['_meta']['requested_driver']);
        Http::assertSentCount(2);
    }

    public function test_bad_request_exposes_only_a_safe_code_and_logs_safe_identifiers(): void
    {
        $rawText = 'Birth Date: 21 April 2002 PRIVATE_CV_MARKER';
        Log::spy();
        Http::fake(['api.groq.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid schema PRIVATE_PROVIDER_BODY',
                'type' => 'invalid_request_error',
                'code' => 'json_schema_invalid',
            ],
        ], 400)]);

        try {
            $this->parser()->parse($rawText);
            $this->fail('Expected bad request failure.');
        } catch (CVParserException $exception) {
            $this->assertSame('GROQ_BAD_REQUEST', $exception->reasonCode);
            $this->assertSame('GROQ_BAD_REQUEST', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE_CV_MARKER', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY', $exception->getMessage());
            $this->assertStringNotContainsString('groq-test-key', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with(
            'Groq CV parser request was rejected.',
            [
                'http_status' => 400,
                'error_type' => 'invalid_request_error',
                'error_code' => 'json_schema_invalid',
                'structured_output_mode' => 'json_schema_strict',
                'max_completion_tokens' => 4096,
                'reasoning_effort' => 'low',
            ],
        );
        Http::assertSentCount(1);
    }

    #[DataProvider('productionBirthDateProvider')]
    public function test_production_cv_birth_date_is_normalized_without_rejecting_the_cv(?string $providerDate, ?string $expected): void
    {
        $rawText = <<<'TEXT'
PERSONAL INFORMATION
Birth Date: 21 April 2002

EXPERIENCE
January 2026 - Present
Laravel Developer
FutureX | Jordan (remote)
TEXT;
        $data = $this->validParsed();
        $data['birth_date'] = $providerDate;
        Http::fake(['api.groq.com/*' => Http::response($this->responsePayload($data), 200)]);

        $parsed = (new CVParsedDataNormalizer)->normalize($this->parser()->parse($rawText), $rawText);

        $this->assertSame($expected, $parsed['birth_date']);
        $this->assertSame('groq', $parsed['_meta']['parser_driver']);
    }

    public static function productionBirthDateProvider(): array
    {
        return [
            'ISO date' => ['2002-04-21', '2002-04-21'],
            'day month year' => ['21 April 2002', '2002-04-21'],
            'partial date' => ['2002-04', null],
        ];
    }

    public function test_timeout_can_fallback_to_rules(): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        Http::fake(fn () => throw new ConnectionException('transport details'));

        $parsed = $this->parser()->parse("Skills\nLaravel");

        $this->assertSame(['Laravel'], $parsed['skills']);
        $this->assertSame('GROQ_TIMEOUT', $parsed['_meta']['fallback_reason']);
    }

    #[DataProvider('authenticationFailureProvider')]
    public function test_authentication_failures_never_fallback_to_rules(?int $status): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        if ($status === null) {
            config()->set('cv.groq.api_key', '');
            Http::fake();
        } else {
            Http::fake(['api.groq.com/*' => Http::response([], $status)]);
        }

        try {
            $this->parser()->parse("Skills\nLaravel");
            $this->fail('Authentication failure must not return a rules result.');
        } catch (CVParserException $exception) {
            $this->assertSame('GROQ_AUTHENTICATION_FAILED', $exception->reasonCode);
        }
    }

    public static function authenticationFailureProvider(): array
    {
        return ['missing key' => [null], 'unauthorized' => [401], 'forbidden' => [403]];
    }

    private function parser(): GroqCVTextParser
    {
        return $this->app->make(GroqCVTextParser::class);
    }

    private function responsePayload(array $data): array
    {
        return ['choices' => [['message' => ['content' => json_encode($data, JSON_THROW_ON_ERROR)]]]];
    }

    private function jsonValidationFailure(): array
    {
        return [
            'error' => [
                'message' => 'Provider body must not be logged.',
                'type' => 'invalid_request_error',
                'code' => 'json_validate_failed',
            ],
        ];
    }

    private function validParsed(): array
    {
        return [
            'full_name' => null, 'email' => null, 'phone' => null, 'location' => null,
            'birth_date' => null, 'summary' => null, 'experience' => [], 'education' => [],
            'skills' => [], 'languages' => [],
        ];
    }

    private function experience(string $title, string $company, string $startDate, ?string $endDate, bool $current, string $responsibility): array
    {
        return [
            'title' => $title, 'company_name' => $company, 'location' => null, 'work_mode' => null,
            'start_date' => $startDate, 'end_date' => $endDate, 'is_current' => $current,
            'description' => null, 'responsibilities' => [$responsibility],
            'evidence' => "{$title}\n{$company}\n{$responsibility}", 'confidence_score' => 1,
        ];
    }
}
