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
        $this->assertSame('1.0', $parsed['_meta']['schema_version']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer groq-test-key')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request['model'] === 'configured-groq-model'
                && $request['messages'][1]['content'] === 'Laravel Developer at FutureX'
                && $request['response_format']['type'] === 'json_schema'
                && $request['response_format']['json_schema']['strict'] === true
                && $request['response_format']['json_schema']['schema']['additionalProperties'] === false;
        });
    }

    public function test_regression_fixture_produces_safe_experience_education_and_skills(): void
    {
        $rawText = <<<'TEXT'
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

        $this->assertSame('Software Developer', $parsed['experience'][0]['title']);
        $this->assertSame('Opti Tech', $parsed['experience'][0]['company_name']);
        $this->assertSame("Bachelor's degree", $parsed['education'][0]['degree']);
        $this->assertSame('Information Technology', $parsed['education'][0]['field_of_study']);
        $this->assertSame(['Laravel', 'MySQL'], $parsed['skills']);
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
    public function test_invalid_responses_are_rejected(array $payload): void
    {
        Http::fake(['api.groq.com/*' => Http::response($payload, 200)]);

        $this->expectExceptionObject(new CVParserException('GROQ_INVALID_RESPONSE'));
        $this->parser()->parse('CV');
    }

    public static function invalidResponseProvider(): array
    {
        return [
            'invalid json' => [['choices' => [['message' => ['content' => '{bad']]]]],
            'missing choices' => [[]],
            'invalid schema' => [['choices' => [['message' => ['content' => '{}']]]]],
            'refusal' => [['choices' => [['message' => ['content' => '{}', 'refusal' => 'No']]]]],
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
            'invalid response' => ['GROQ_INVALID_RESPONSE', 200],
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

    private function validParsed(): array
    {
        return [
            'full_name' => null, 'email' => null, 'phone' => null, 'location' => null,
            'birth_date' => null, 'summary' => null, 'experience' => [], 'education' => [],
            'skills' => [], 'languages' => [],
        ];
    }
}
