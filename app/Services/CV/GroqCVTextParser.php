<?php

namespace App\Services\CV;

use App\Contracts\CV\CVTextParser;
use App\Exceptions\CVParserException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class GroqCVTextParser implements CVTextParser
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    private const FALLBACK_REASON_CODES = [
        'GROQ_RATE_LIMITED',
        'GROQ_TIMEOUT',
        'GROQ_UNAVAILABLE',
        'GROQ_EMPTY_CONTENT',
        'GROQ_INVALID_JSON',
        'GROQ_CONTRACT_MISMATCH',
    ];

    public function __construct(
        private readonly RuleBasedCVTextParser $rulesParser,
        private readonly CVParsingPrompt $prompt,
        private readonly CVParsingSchema $schema,
    ) {}

    /** @return array<string, mixed> */
    public function parse(string $rawText): array
    {
        try {
            return $this->parseWithGroq($rawText);
        } catch (CVParserException $exception) {
            if (! config('cv.parser.fallback_to_rules', true)
                || ! in_array($exception->reasonCode, self::FALLBACK_REASON_CODES, true)) {
                throw $exception;
            }

            $fallback = $this->rulesParser->parse($rawText);
            $fallback['_meta'] = [
                'parser_driver' => 'rules',
                'requested_driver' => 'groq',
                'fallback_used' => true,
                'fallback_reason' => $exception->reasonCode,
                'schema_version' => '1.0',
            ];

            return $fallback;
        }
    }

    /** @return array<string, mixed> */
    private function parseWithGroq(string $rawText): array
    {
        $apiKey = trim((string) config('cv.groq.api_key'));
        if ($apiKey === '') {
            throw new CVParserException('GROQ_AUTHENTICATION_FAILED');
        }

        $response = $this->sendWithLimitedRetry($apiKey, $rawText);
        $this->assertSuccessfulResponse($response);
        $payload = $response->json();
        $message = is_array($payload) ? ($payload['choices'][0]['message'] ?? null) : null;

        if (is_array($message) && array_key_exists('refusal', $message)) {
            throw new CVParserException('GROQ_REFUSAL');
        }

        $content = is_array($message) ? ($message['content'] ?? null) : null;
        if (! is_string($content) || trim($content) === '') {
            throw new CVParserException('GROQ_EMPTY_CONTENT');
        }

        try {
            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CVParserException('GROQ_INVALID_JSON');
        }

        if (! is_array($parsed) || ! $this->schema->matches($parsed)) {
            throw new CVParserException('GROQ_CONTRACT_MISMATCH');
        }

        $parsed['_meta'] = [
            'parser_driver' => 'groq',
            'model' => (string) config('cv.groq.model'),
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
                    ->connectTimeout((int) config('cv.groq.connect_timeout', 10))
                    ->timeout((int) config('cv.groq.timeout', 60))
                    ->post(self::ENDPOINT, $this->requestBody($rawText));
            } catch (ConnectionException) {
                throw new CVParserException('GROQ_TIMEOUT');
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
            throw new CVParserException('GROQ_AUTHENTICATION_FAILED');
        }
        if ($response->status() === 429) {
            throw new CVParserException('GROQ_RATE_LIMITED');
        }
        if ($response->serverError()) {
            throw new CVParserException('GROQ_UNAVAILABLE');
        }
        if ($response->status() === 400) {
            Log::warning('Groq CV parser request was rejected.', [
                'http_status' => 400,
                'error_type' => $this->safeErrorIdentifier($response->json('error.type')),
                'error_code' => $this->safeErrorIdentifier($response->json('error.code')),
            ]);

            throw new CVParserException('GROQ_BAD_REQUEST');
        }
        if (! $response->successful()) {
            throw new CVParserException('GROQ_BAD_REQUEST');
        }
    }

    private function safeErrorIdentifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $value) === 1
            ? $value
            : null;
    }

    /** @return array<string, mixed> */
    private function requestBody(string $rawText): array
    {
        return [
            'model' => (string) config('cv.groq.model', 'openai/gpt-oss-20b'),
            'messages' => [
                ['role' => 'system', 'content' => $this->prompt->text()],
                ['role' => 'user', 'content' => $rawText],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'cv_parsing_result',
                    'strict' => true,
                    'schema' => $this->schema->definition(),
                ],
            ],
        ];
    }
}
