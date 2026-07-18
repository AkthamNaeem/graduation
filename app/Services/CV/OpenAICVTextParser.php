<?php

namespace App\Services\CV;

use App\Contracts\CV\CVTextParser;
use App\Exceptions\CVParserException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use JsonException;

class OpenAICVTextParser implements CVTextParser
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(
        private readonly RuleBasedCVTextParser $rulesParser,
    ) {}

    /** @return array<string, mixed> */
    public function parse(string $rawText): array
    {
        try {
            return $this->parseWithOpenAI($rawText);
        } catch (CVParserException $exception) {
            if (! config('cv.parser.fallback_to_rules', true)) {
                throw $exception;
            }

            $fallback = $this->rulesParser->parse($rawText);
            $fallback['_meta'] = [
                'parser_driver' => 'rules',
                'requested_driver' => 'openai',
                'fallback_used' => true,
                'fallback_reason' => $exception->reasonCode,
                'schema_version' => '1.0',
            ];

            return $fallback;
        }
    }

    /** @return array<string, mixed> */
    private function parseWithOpenAI(string $rawText): array
    {
        $apiKey = trim((string) config('cv.openai.api_key'));
        if ($apiKey === '') {
            throw new CVParserException('OPENAI_AUTHENTICATION_FAILED');
        }

        $response = $this->sendWithLimitedRetry($apiKey, $rawText);
        $this->assertSuccessfulResponse($response);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new CVParserException('OPENAI_INVALID_RESPONSE');
        }

        $outputText = null;
        foreach ($payload['output'] ?? [] as $output) {
            if (! is_array($output)) {
                continue;
            }
            foreach ($output['content'] ?? [] as $content) {
                if (! is_array($content)) {
                    continue;
                }
                if (($content['type'] ?? null) === 'refusal') {
                    throw new CVParserException('OPENAI_INVALID_RESPONSE');
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $outputText = $content['text'];
                    break 2;
                }
            }
        }

        if ($outputText === null || trim($outputText) === '') {
            throw new CVParserException('OPENAI_INVALID_RESPONSE');
        }

        try {
            $parsed = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CVParserException('OPENAI_INVALID_RESPONSE');
        }

        if (! is_array($parsed) || ! $this->matchesContract($parsed)) {
            throw new CVParserException('OPENAI_INVALID_RESPONSE');
        }

        $parsed['_meta'] = [
            'parser_driver' => 'openai',
            'model' => (string) config('cv.openai.model'),
            'fallback_used' => false,
            'schema_version' => '1.0',
        ];

        return $parsed;
    }

    private function sendWithLimitedRetry(string $apiKey, string $rawText): Response
    {
        $attempts = 0;
        do {
            $attempts++;
            try {
                $response = Http::acceptJson()
                    ->withToken($apiKey)
                    ->connectTimeout((int) config('cv.openai.connect_timeout', 10))
                    ->timeout((int) config('cv.openai.timeout', 60))
                    ->post(self::ENDPOINT, $this->requestBody($rawText));
            } catch (ConnectionException) {
                throw new CVParserException('OPENAI_TIMEOUT');
            }

            if (! ($response->status() === 429 || $response->serverError()) || $attempts >= 3) {
                return $response;
            }

            usleep(100000 * $attempts);
        } while (true);
    }

    private function assertSuccessfulResponse(Response $response): void
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new CVParserException('OPENAI_AUTHENTICATION_FAILED');
        }
        if ($response->status() === 429) {
            throw new CVParserException('OPENAI_RATE_LIMITED');
        }
        if ($response->serverError()) {
            throw new CVParserException('OPENAI_UNAVAILABLE');
        }
        if (! $response->successful()) {
            throw new CVParserException('OPENAI_INVALID_RESPONSE');
        }
    }

    /** @return array<string, mixed> */
    private function requestBody(string $rawText): array
    {
        return [
            'model' => (string) config('cv.openai.model', 'gpt-5-mini'),
            'store' => false,
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $this->systemPrompt()]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $rawText]]],
            ],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'cv_parsing_result',
                'strict' => true,
                'schema' => $this->schema(),
            ]],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract structured facts from CV text.

Rules:
1. Use only facts explicitly supported by the supplied CV text.
2. Never invent employers, job titles, dates, degrees, institutions, skills, languages, or personal information.
3. Return null or an empty array when information is unavailable.
4. A date range is not a job title or company.
5. "Present", "Current", month names, and years cannot be company names.
6. Bullet-point responsibilities are not separate work experiences.
7. Group adjacent lines belonging to the same experience or education entry.
8. Preserve employer and job-title wording from the CV when possible.
9. Normalize dates to YYYY-MM when the month is available and YYYY when only the year is available.
10. Use null as end_date and is_current=true for current positions.
11. Include short evidence copied from the CV for each extracted experience and education item.
12. Do not treat generic prose as a skill unless it is explicitly present in a skills section or clearly used as a technology.
13. Return data only through the supplied JSON schema.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['full_name', 'email', 'phone', 'location', 'birth_date', 'summary', 'experience', 'education', 'skills', 'languages'],
            'properties' => [
                'full_name' => $nullableString,
                'email' => $nullableString,
                'phone' => $nullableString,
                'location' => $nullableString,
                'birth_date' => $nullableString,
                'summary' => $nullableString,
                'experience' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'company_name', 'location', 'work_mode', 'start_date', 'end_date', 'is_current', 'description', 'responsibilities', 'evidence', 'confidence_score'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'company_name' => ['type' => 'string'],
                            'location' => $nullableString,
                            'work_mode' => ['type' => ['string', 'null'], 'enum' => ['remote', 'onsite', 'hybrid', null]],
                            'start_date' => $nullableString,
                            'end_date' => $nullableString,
                            'is_current' => ['type' => 'boolean'],
                            'description' => $nullableString,
                            'responsibilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'evidence' => ['type' => 'string'],
                            'confidence_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
                'education' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['degree', 'field_of_study', 'institution', 'start_year', 'graduation_year', 'is_expected', 'description', 'evidence', 'confidence_score'],
                        'properties' => [
                            'degree' => $nullableString,
                            'field_of_study' => $nullableString,
                            'institution' => ['type' => 'string'],
                            'start_year' => ['type' => ['integer', 'null']],
                            'graduation_year' => ['type' => ['integer', 'null']],
                            'is_expected' => ['type' => 'boolean'],
                            'description' => $nullableString,
                            'evidence' => ['type' => 'string'],
                            'confidence_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
                'skills' => ['type' => 'array', 'items' => ['type' => 'string']],
                'languages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'level'],
                        'properties' => ['name' => ['type' => 'string'], 'level' => $nullableString],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $parsed */
    private function matchesContract(array $parsed): bool
    {
        if (array_diff(array_keys($parsed), ['full_name', 'email', 'phone', 'location', 'birth_date', 'summary', 'experience', 'education', 'skills', 'languages']) !== []) {
            return false;
        }

        $validator = Validator::make($parsed, [
            'full_name' => ['present', 'nullable', 'string'],
            'email' => ['present', 'nullable', 'string'],
            'phone' => ['present', 'nullable', 'string'],
            'location' => ['present', 'nullable', 'string'],
            'birth_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'summary' => ['present', 'nullable', 'string'],
            'experience' => ['present', 'array'],
            'experience.*' => ['array:title,company_name,location,work_mode,start_date,end_date,is_current,description,responsibilities,evidence,confidence_score'],
            'experience.*.title' => ['required', 'string'],
            'experience.*.company_name' => ['required', 'string'],
            'experience.*.location' => ['present', 'nullable', 'string'],
            'experience.*.work_mode' => ['present', 'nullable', 'in:remote,onsite,hybrid'],
            'experience.*.start_date' => ['present', 'nullable', 'regex:/^\d{4}(?:-(?:0[1-9]|1[0-2]))?$/'],
            'experience.*.end_date' => ['present', 'nullable', 'regex:/^\d{4}(?:-(?:0[1-9]|1[0-2]))?$/'],
            'experience.*.is_current' => ['required', 'boolean'],
            'experience.*.description' => ['present', 'nullable', 'string'],
            'experience.*.responsibilities' => ['present', 'array'],
            'experience.*.responsibilities.*' => ['string'],
            'experience.*.evidence' => ['required', 'string'],
            'experience.*.confidence_score' => ['required', 'numeric', 'between:0,1'],
            'education' => ['present', 'array'],
            'education.*' => ['array:degree,field_of_study,institution,start_year,graduation_year,is_expected,description,evidence,confidence_score'],
            'education.*.degree' => ['present', 'nullable', 'string'],
            'education.*.field_of_study' => ['present', 'nullable', 'string'],
            'education.*.institution' => ['required', 'string'],
            'education.*.start_year' => ['present', 'nullable', 'integer'],
            'education.*.graduation_year' => ['present', 'nullable', 'integer'],
            'education.*.is_expected' => ['required', 'boolean'],
            'education.*.description' => ['present', 'nullable', 'string'],
            'education.*.evidence' => ['required', 'string'],
            'education.*.confidence_score' => ['required', 'numeric', 'between:0,1'],
            'skills' => ['present', 'array'],
            'skills.*' => ['string'],
            'languages' => ['present', 'array'],
            'languages.*' => ['array:name,level'],
            'languages.*.name' => ['required', 'string'],
            'languages.*.level' => ['present', 'nullable', 'string'],
        ]);

        return ! $validator->fails();
    }
}
