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

    private const JSON_SCHEMA_STRICT = 'json_schema_strict';

    private const JSON_OBJECT_FALLBACK = 'json_object_fallback';

    private const FALLBACK_REASON_CODES = [
        'GROQ_RATE_LIMITED',
        'GROQ_TIMEOUT',
        'GROQ_UNAVAILABLE',
        'GROQ_EMPTY_CONTENT',
        'GROQ_INVALID_JSON',
        'GROQ_CONTRACT_MISMATCH',
        'GROQ_JSON_GENERATION_FAILED',
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

        $requestOptions = $this->requestOptions();
        $response = $this->sendWithLimitedRetry($apiKey, $rawText, self::JSON_SCHEMA_STRICT, 3, $requestOptions);
        $structuredOutputMode = self::JSON_SCHEMA_STRICT;
        $structuredOutputFallbackReason = null;

        if ($this->isJsonValidationFailure($response)) {
            Log::warning('Groq strict structured output failed; trying JSON object fallback.', [
                'http_status' => 400,
                'error_type' => $this->safeErrorIdentifier($response->json('error.type')),
                'error_code' => 'json_validate_failed',
                'structured_output_mode' => self::JSON_SCHEMA_STRICT,
                'max_completion_tokens' => $requestOptions['max_completion_tokens'],
                'reasoning_effort' => $requestOptions['reasoning_effort'],
            ]);

            $response = $this->sendWithLimitedRetry($apiKey, $rawText, self::JSON_OBJECT_FALLBACK, 1, $requestOptions);
            $structuredOutputMode = self::JSON_OBJECT_FALLBACK;
            $structuredOutputFallbackReason = 'json_validate_failed';

            if ($this->isJsonValidationFailure($response)) {
                $this->logBadRequest($response, $structuredOutputMode, $requestOptions);

                throw new CVParserException('GROQ_JSON_GENERATION_FAILED');
            }
        }

        $this->assertSuccessfulResponse($response, $structuredOutputMode, $requestOptions);
        $parsed = $this->parseResponse($response);

        $parsed['_meta'] = [
            'parser_driver' => 'groq',
            'model' => (string) config('cv.groq.model'),
            'fallback_used' => false,
            'structured_output_mode' => $structuredOutputMode,
            'schema_version' => '1.0',
        ];
        if ($structuredOutputFallbackReason !== null) {
            $parsed['_meta']['structured_output_fallback_reason'] = $structuredOutputFallbackReason;
        }

        return $parsed;
    }

    /** @param array{max_completion_tokens: int, reasoning_effort: string, temperature: float} $requestOptions */
    private function sendWithLimitedRetry(string $apiKey, string $rawText, string $mode, int $maximumAttempts, array $requestOptions): Response
    {
        $attempts = 0;
        do {
            $attempts++;
            try {
                $response = Http::acceptJson()
                    ->withToken($apiKey)
                    ->connectTimeout((int) config('cv.groq.connect_timeout', 10))
                    ->timeout((int) config('cv.groq.timeout', 60))
                    ->post(self::ENDPOINT, $this->requestBody($rawText, $mode, $requestOptions));
            } catch (ConnectionException) {
                throw new CVParserException('GROQ_TIMEOUT');
            }

            if (! ($response->status() === 429 || $response->serverError()) || $attempts >= $maximumAttempts) {
                return $response;
            }

            usleep(100000 * $attempts);
        } while (true);
    }

    /** @return array<string, mixed> */
    private function parseResponse(Response $response): array
    {
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

        return $parsed;
    }

    private function isJsonValidationFailure(Response $response): bool
    {
        return $response->status() === 400
            && $this->safeErrorIdentifier($response->json('error.code')) === 'json_validate_failed';
    }

    /** @param array{max_completion_tokens: int, reasoning_effort: string, temperature: float} $requestOptions */
    private function assertSuccessfulResponse(Response $response, string $mode, array $requestOptions): void
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
            $this->logBadRequest($response, $mode, $requestOptions);

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

    /** @param array{max_completion_tokens: int, reasoning_effort: string, temperature: float} $requestOptions */
    private function logBadRequest(Response $response, string $mode, array $requestOptions): void
    {
        Log::warning('Groq CV parser request was rejected.', [
            'http_status' => 400,
            'error_type' => $this->safeErrorIdentifier($response->json('error.type')),
            'error_code' => $this->safeErrorIdentifier($response->json('error.code')),
            'structured_output_mode' => $mode,
            'max_completion_tokens' => $requestOptions['max_completion_tokens'],
            'reasoning_effort' => $requestOptions['reasoning_effort'],
        ]);
    }

    /** @return array<string, mixed> */
    /**
     * @param  array{max_completion_tokens: int, reasoning_effort: string, temperature: float}  $requestOptions
     * @return array<string, mixed>
     */
    private function requestBody(string $rawText, string $mode, array $requestOptions): array
    {
        $body = [
            'model' => (string) config('cv.groq.model', 'openai/gpt-oss-20b'),
            'max_completion_tokens' => $requestOptions['max_completion_tokens'],
            'reasoning_effort' => $requestOptions['reasoning_effort'],
            'include_reasoning' => false,
            'temperature' => $requestOptions['temperature'],
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($mode)],
                ['role' => 'user', 'content' => $rawText],
            ],
        ];

        $body['response_format'] = $mode === self::JSON_OBJECT_FALLBACK
            ? ['type' => 'json_object']
            : [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'cv_parsing_result',
                    'strict' => true,
                    'schema' => $this->schema->definition(),
                ],
            ];

        return $body;
    }

    private function systemPrompt(string $mode): string
    {
        if ($mode !== self::JSON_OBJECT_FALLBACK) {
            return $this->prompt->text();
        }

        return $this->prompt->jsonObjectText();
    }

    /** @return array{max_completion_tokens: int, reasoning_effort: string, temperature: float} */
    private function requestOptions(): array
    {
        $maxCompletionTokens = config('cv.groq.max_completion_tokens', 8192);
        if (! is_numeric($maxCompletionTokens)
            || (int) $maxCompletionTokens < 1024
            || (int) $maxCompletionTokens > 16384) {
            $maxCompletionTokens = 8192;
        }

        $reasoningEffort = config('cv.groq.reasoning_effort', 'low');
        if (! is_string($reasoningEffort) || ! in_array($reasoningEffort, ['low', 'medium', 'high'], true)) {
            $reasoningEffort = 'low';
        }

        $temperature = config('cv.groq.temperature', 0.5);
        if (! is_numeric($temperature) || (float) $temperature < 0 || (float) $temperature > 2) {
            $temperature = 0.5;
        }

        return [
            'max_completion_tokens' => (int) $maxCompletionTokens,
            'reasoning_effort' => $reasoningEffort,
            'temperature' => (float) $temperature,
        ];
    }
}
