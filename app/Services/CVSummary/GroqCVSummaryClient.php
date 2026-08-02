<?php

namespace App\Services\CVSummary;

use App\Contracts\CVSummary\CVSummaryClient;
use App\Exceptions\CVSummaryGenerationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class GroqCVSummaryClient implements CVSummaryClient
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    private const JSON_SCHEMA = 'json_schema';

    private const JSON_OBJECT = 'json_object';

    public function __construct(
        private readonly CVSummaryPrompt $prompt,
        private readonly CVSummarySchema $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $source
     * @return array{data: array<string, mixed>, request_id: ?string}
     */
    public function generate(array $source, string $locale): array
    {
        $apiKey = trim((string) config('cv_summary.groq.api_key'));
        if ($apiKey === '') {
            throw new CVSummaryGenerationException(
                __('cv_summary.authentication_failed'),
                'CV_SUMMARY_AUTHENTICATION_FAILED',
                503,
            );
        }

        $options = $this->requestOptions();
        $mode = self::JSON_SCHEMA;
        $response = $this->sendWithLimitedRetry($apiKey, $source, $locale, $mode, 3, $options);

        if ($this->isJsonValidationFailure($response)) {
            $this->logJsonSchemaFallback($response);
            $mode = self::JSON_OBJECT;
            $response = $this->sendWithLimitedRetry($apiKey, $source, $locale, $mode, 1, $options);
        }

        $this->assertSuccessful($response, $mode);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw $this->invalidResponse();
        }

        $message = $payload['choices'][0]['message'] ?? null;
        if (! is_array($message) || array_key_exists('refusal', $message)) {
            throw $this->invalidResponse();
        }

        $content = $message['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw $this->invalidResponse();
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidResponse();
        }

        if (! is_array($data) || ! $this->schema->matches($data)) {
            throw $this->invalidResponse();
        }

        return [
            'data' => $data,
            'request_id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
        ];
    }

    public function provider(): string
    {
        return 'groq';
    }

    public function model(): string
    {
        return (string) config('cv_summary.groq.model', 'openai/gpt-oss-20b');
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array{max_completion_tokens: int, reasoning_effort: string, temperature: float}  $options
     */
    private function sendWithLimitedRetry(
        string $apiKey,
        array $source,
        string $locale,
        string $mode,
        int $maximumAttempts,
        array $options,
    ): Response {
        $attempts = 0;

        do {
            $attempts++;

            try {
                $response = Http::acceptJson()
                    ->withToken($apiKey)
                    ->connectTimeout((int) config('cv_summary.groq.connect_timeout', 10))
                    ->timeout((int) config('cv_summary.groq.timeout', 60))
                    ->post(self::ENDPOINT, $this->requestBody($source, $locale, $mode, $options));
            } catch (ConnectionException) {
                throw new CVSummaryGenerationException(
                    __('cv_summary.timeout'),
                    'CV_SUMMARY_TIMEOUT',
                    503,
                );
            }

            if (! ($response->status() === 429 || $response->serverError()) || $attempts >= $maximumAttempts) {
                return $response;
            }

            usleep(100000 * $attempts);
        } while (true);
    }

    private function assertSuccessful(Response $response, string $mode): void
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new CVSummaryGenerationException(
                __('cv_summary.authentication_failed'),
                'CV_SUMMARY_AUTHENTICATION_FAILED',
                503,
            );
        }

        if ($response->status() === 429) {
            throw new CVSummaryGenerationException(
                __('cv_summary.rate_limited'),
                'CV_SUMMARY_RATE_LIMITED',
                503,
            );
        }

        if ($response->serverError()) {
            throw new CVSummaryGenerationException(
                __('cv_summary.provider_unavailable'),
                'CV_SUMMARY_PROVIDER_UNAVAILABLE',
                503,
            );
        }

        if ($response->status() === 400) {
            $this->logBadRequest($response, $mode);
        }

        if (! $response->successful()) {
            throw $this->invalidResponse();
        }
    }

    private function isJsonValidationFailure(Response $response): bool
    {
        return $response->status() === 400
            && $this->safeErrorIdentifier($response->json('error.code')) === 'json_validate_failed';
    }

    private function safeErrorIdentifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $value) === 1
            ? $value
            : null;
    }

    private function logJsonSchemaFallback(Response $response): void
    {
        Log::warning('Groq CV summary strict structured output failed; trying JSON object fallback.', [
            'provider' => $this->provider(),
            'http_status' => 400,
            'error_type' => $this->safeErrorIdentifier($response->json('error.type')),
            'error_code' => 'json_validate_failed',
            'structured_output_mode' => self::JSON_SCHEMA,
            'model' => $this->model(),
        ]);
    }

    private function logBadRequest(Response $response, string $mode): void
    {
        Log::warning('Groq CV summary request was rejected.', [
            'provider' => $this->provider(),
            'http_status' => 400,
            'error_type' => $this->safeErrorIdentifier($response->json('error.type')),
            'error_code' => $this->safeErrorIdentifier($response->json('error.code')),
            'structured_output_mode' => $mode,
            'model' => $this->model(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array{max_completion_tokens: int, reasoning_effort: string, temperature: float}  $options
     * @return array<string, mixed>
     */
    private function requestBody(array $source, string $locale, string $mode, array $options): array
    {
        $body = [
            'model' => $this->model(),
            'max_completion_tokens' => $options['max_completion_tokens'],
            'reasoning_effort' => $options['reasoning_effort'],
            'include_reasoning' => false,
            'temperature' => $options['temperature'],
            'stream' => false,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $mode === self::JSON_OBJECT
                        ? $this->prompt->jsonObjectText($locale)
                        : $this->prompt->text($locale),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode(
                        $source,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ),
                ],
            ],
        ];

        $body['response_format'] = $mode === self::JSON_OBJECT
            ? ['type' => 'json_object']
            : [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'application_cv_summary',
                    'strict' => true,
                    'schema' => $this->schema->definition(),
                ],
            ];

        return $body;
    }

    /** @return array{max_completion_tokens: int, reasoning_effort: string, temperature: float} */
    private function requestOptions(): array
    {
        $maxCompletionTokens = config('cv_summary.groq.max_completion_tokens', 2048);
        if (! is_numeric($maxCompletionTokens)
            || (int) $maxCompletionTokens < 512
            || (int) $maxCompletionTokens > 16384) {
            $maxCompletionTokens = 2048;
        }

        $reasoningEffort = config('cv_summary.groq.reasoning_effort', 'low');
        if (! is_string($reasoningEffort) || ! in_array($reasoningEffort, ['low', 'medium', 'high'], true)) {
            $reasoningEffort = 'low';
        }

        $temperature = config('cv_summary.groq.temperature', 0.2);
        if (! is_numeric($temperature) || (float) $temperature < 0 || (float) $temperature > 2) {
            $temperature = 0.2;
        }

        return [
            'max_completion_tokens' => (int) $maxCompletionTokens,
            'reasoning_effort' => $reasoningEffort,
            'temperature' => (float) $temperature,
        ];
    }

    private function invalidResponse(): CVSummaryGenerationException
    {
        return new CVSummaryGenerationException(
            __('cv_summary.invalid_response'),
            'CV_SUMMARY_INVALID_RESPONSE',
            502,
        );
    }
}
