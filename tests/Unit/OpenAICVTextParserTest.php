<?php

namespace Tests\Unit;

use App\Exceptions\CVParserException;
use App\Models\Skill;
use App\Services\CV\CVParsedDataNormalizer;
use App\Services\CV\OpenAICVTextParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAICVTextParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cv.openai.api_key', 'test-key');
        config()->set('cv.openai.model', 'gpt-5-mini-test');
        config()->set('cv.parser.fallback_to_rules', false);
    }

    public function test_it_sends_a_private_strict_structured_responses_request(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->responsePayload($this->validParsed()), 200)]);

        $parsed = $this->parser()->parse('Laravel Developer at FutureX');

        $this->assertSame('openai', $parsed['_meta']['parser_driver']);
        $this->assertSame('gpt-5-mini-test', $parsed['_meta']['model']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'gpt-5-mini-test'
                && $request['store'] === false
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request['input'][1]['content'][0]['text'] === 'Laravel Developer at FutureX';
        });
    }

    public function test_regression_fixture_produces_three_safe_experiences_full_education_and_skills(): void
    {
        $rawText = $this->regressionCv();
        $data = $this->validParsed();
        $data['experience'] = [
            $this->experience('Laravel Developer', 'FutureX', "January 2026 - Present\nLaravel Developer\nFutureX | Jordan (remote)", '2026-01', null, true),
            $this->experience('Software Developer', 'Opti Tech', "October 2024 - December 2025\nSoftware Developer\nOpti Tech | UAE (remote)", '2024-10', '2025-12'),
            $this->experience('Web Developer', 'Freelance', "January 2025 - Present\nWeb Developer\nFreelance", '2025-01', null, true),
        ];
        $data['education'] = [[
            'degree' => "Bachelor's degree", 'field_of_study' => 'Information Technology',
            'institution' => 'Damascus University', 'start_year' => 2020, 'graduation_year' => 2026,
            'is_expected' => true, 'description' => null,
            'evidence' => "2020 - 2026 (Expected)\nBachelor's degree, Information Technology\nDamascus University",
            'confidence_score' => 0.98,
        ]];
        $data['skills'] = ['Angular', 'React', 'React Native', 'TypeScript', 'JavaScript', 'Laravel', 'MySQL', 'Git', 'Postman', 'Swagger'];
        Http::fake(['api.openai.com/*' => Http::response($this->responsePayload($data), 200)]);

        $parsed = (new CVParsedDataNormalizer)->normalize($this->parser()->parse($rawText), $rawText);

        $this->assertSame(['Laravel Developer', 'Software Developer', 'Web Developer'], array_column($parsed['experience'], 'title'));
        $this->assertSame(['FutureX', 'Opti Tech', 'Freelance'], array_column($parsed['experience'], 'company_name'));
        $this->assertSame("Bachelor's degree", $parsed['education'][0]['degree']);
        $this->assertSame('Information Technology', $parsed['education'][0]['field_of_study']);
        $this->assertSame(2026, $parsed['education'][0]['graduation_year']);
        $this->assertSame($data['skills'], $parsed['skills']);
    }

    #[DataProvider('terminalFailureProvider')]
    public function test_terminal_http_failures_have_safe_codes(int $status, string $code, int $attempts): void
    {
        Http::fake(['api.openai.com/*' => Http::response([], $status)]);

        try {
            $this->parser()->parse('CV');
            $this->fail('Expected parser exception.');
        } catch (CVParserException $exception) {
            $this->assertSame($code, $exception->reasonCode);
            Http::assertSentCount($attempts);
        }
    }

    public static function terminalFailureProvider(): array
    {
        return [
            [401, 'OPENAI_AUTHENTICATION_FAILED', 1],
            [403, 'OPENAI_AUTHENTICATION_FAILED', 1],
            [429, 'OPENAI_RATE_LIMITED', 3],
            [500, 'OPENAI_UNAVAILABLE', 3],
        ];
    }

    public function test_missing_key_fails_without_request(): void
    {
        config()->set('cv.openai.api_key', '');
        Http::fake();

        $this->expectExceptionObject(new CVParserException('OPENAI_AUTHENTICATION_FAILED'));
        $this->parser()->parse('CV');
    }

    #[DataProvider('authenticationFailureProvider')]
    public function test_authentication_failures_never_fallback_to_rules(?int $status): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        if ($status === null) {
            config()->set('cv.openai.api_key', '');
            Http::fake();
        } else {
            Http::fake(['api.openai.com/*' => Http::response([], $status)]);
        }

        try {
            $this->parser()->parse("Skills\nLaravel");
            $this->fail('Authentication failure must not return a rules result.');
        } catch (CVParserException $exception) {
            $this->assertSame('OPENAI_AUTHENTICATION_FAILED', $exception->reasonCode);
        }
    }

    public static function authenticationFailureProvider(): array
    {
        return [
            'missing key' => [null],
            'unauthorized' => [401],
            'forbidden' => [403],
        ];
    }

    public function test_connection_failure_is_a_safe_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('secret transport details'));

        $this->expectExceptionObject(new CVParserException('OPENAI_TIMEOUT'));
        $this->parser()->parse('CV');
    }

    #[DataProvider('invalidResponseProvider')]
    public function test_invalid_responses_are_rejected(array $payload): void
    {
        Http::fake(['api.openai.com/*' => Http::response($payload, 200)]);

        $this->expectExceptionObject(new CVParserException('OPENAI_INVALID_RESPONSE'));
        $this->parser()->parse('CV');
    }

    public static function invalidResponseProvider(): array
    {
        return [
            'no output text' => [['output' => []]],
            'invalid json' => [['output' => [['content' => [['type' => 'output_text', 'text' => '{bad']]]]]],
            'refusal' => [['output' => [['content' => [['type' => 'refusal', 'refusal' => 'No']]]]]],
            'wrong contract' => [['output' => [['content' => [['type' => 'output_text', 'text' => '{}']]]]]],
        ];
    }

    public function test_enabled_fallback_uses_rules_and_safe_reason_metadata(): void
    {
        Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        config()->set('cv.parser.fallback_to_rules', true);
        Http::fake(['api.openai.com/*' => Http::response([], 500)]);

        $parsed = $this->parser()->parse("Skills\nLaravel");

        $this->assertSame(['Laravel'], $parsed['skills']);
        $this->assertSame('rules', $parsed['_meta']['parser_driver']);
        $this->assertTrue($parsed['_meta']['fallback_used']);
        $this->assertSame('OPENAI_UNAVAILABLE', $parsed['_meta']['fallback_reason']);
    }

    public function test_it_concatenates_all_output_text_parts_in_order(): void
    {
        $json = json_encode($this->validParsed(), JSON_THROW_ON_ERROR);
        $middle = intdiv(strlen($json), 2);
        Http::fake(['api.openai.com/*' => Http::response([
            'output' => [
                ['content' => [['type' => 'output_text', 'text' => substr($json, 0, $middle)]]],
                ['content' => [['type' => 'output_text', 'text' => substr($json, $middle)]]],
            ],
        ], 200)]);

        $parsed = $this->parser()->parse('CV');

        $this->assertSame('openai', $parsed['_meta']['parser_driver']);
        $this->assertSame([], $parsed['experience']);
    }

    public function test_refusal_anywhere_rejects_response_even_with_output_text(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'output' => [[
                'content' => [
                    ['type' => 'output_text', 'text' => json_encode($this->validParsed(), JSON_THROW_ON_ERROR)],
                    ['type' => 'refusal', 'refusal' => 'No'],
                ],
            ]],
        ], 200)]);

        $this->expectExceptionObject(new CVParserException('OPENAI_INVALID_RESPONSE'));
        $this->parser()->parse('CV');
    }

    private function parser(): OpenAICVTextParser
    {
        return $this->app->make(OpenAICVTextParser::class);
    }

    private function responsePayload(array $data): array
    {
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data, JSON_THROW_ON_ERROR)]]]]];
    }

    private function validParsed(): array
    {
        return [
            'full_name' => null, 'email' => null, 'phone' => null, 'location' => null,
            'birth_date' => null, 'summary' => null,
            'experience' => [], 'education' => [], 'skills' => [], 'languages' => [],
        ];
    }

    private function experience(string $title, string $company, string $evidence, string $start, ?string $end, bool $current = false): array
    {
        return [
            'title' => $title, 'company_name' => $company, 'location' => null, 'work_mode' => null,
            'start_date' => $start, 'end_date' => $end, 'is_current' => $current, 'description' => null,
            'responsibilities' => [], 'evidence' => $evidence, 'confidence_score' => 0.98,
        ];
    }

    private function regressionCv(): string
    {
        return <<<'TEXT'
Synthetic Candidate
Phone: +000000000
Email: synthetic.candidate@example.com
Address: Example City

EXPERIENCE

January 2026 - Present
Laravel Developer
FutureX | Jordan (remote)
Developing and maintaining RESTful APIs using Laravel.
Building secure authentication systems using Laravel Sanctum.

October 2024 - December 2025
Software Developer
Opti Tech | UAE (remote)
Built and optimized RESTful APIs using Laravel.

January 2025 - Present
Web Developer
Freelance
Built multiple websites using Angular and React.

EDUCATION

2020 - 2026 (Expected)
Bachelor's degree, Information Technology
Damascus University

SKILLS

Angular
React
React Native
TypeScript
JavaScript
Laravel
MySQL
Git
Postman
Swagger

LANGUAGES

Arabic: Native
English: Intermediate
TEXT;
    }
}
