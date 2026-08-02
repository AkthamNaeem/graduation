<?php

namespace App\Services\CVSummary;

use App\Exceptions\CVSummaryGenerationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class OpenAICVSummaryClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

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
        if (config('cv_summary.provider', 'openai') !== 'openai') {
            throw new CVSummaryGenerationException(
                __('cv_summary.invalid_provider'),
                'CV_SUMMARY_INVALID_PROVIDER',
                500,
            );
        }

        $apiKey = trim((string) config('cv_summary.openai.api_key'));
        if ($apiKey === '') {
            throw new CVSummaryGenerationException(
                __('cv_summary.not_configured'),
                'CV_SUMMARY_NOT_CONFIGURED',
                503,
            );
        }

        $response = $this->sendWithLimitedRetry($apiKey, $source, $locale);
        $this->assertSuccessful($response);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw $this->invalidResponse();
        }

        $outputText = $this->extractOutputText($payload);

        try {
            $data = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);
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

    /** @param array<string, mixed> $source */
    private function sendWithLimitedRetry(string $apiKey, array $source, string $locale): Response
    {
        $attempts = 0;

        do {
            $attempts++;

            try {
                $response = Http::acceptJson()
                    ->withToken($apiKey)
                    ->connectTimeout((int) config('cv_summary.openai.connect_timeout', 10))
                    ->timeout((int) config('cv_summary.openai.timeout', 60))
                    ->post(self::ENDPOINT, $this->requestBody($source, $locale));
            } catch (ConnectionException) {
                throw new CVSummaryGenerationException(
                    __('cv_summary.timeout'),
                    'CV_SUMMARY_TIMEOUT',
                    503,
                );
            }

            if (! ($response->status() === 429 || $response->serverError()) || $attempts >= 3) {
                return $response;
            }

            usleep(100000 * $attempts);
        } while (true);
    }

    private function assertSuccessful(Response $response): void
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

        if (! $response->successful()) {
            throw $this->invalidResponse();
        }
    }

    /** @param array<string, mixed> $payload */
    private function extractOutputText(array $payload): string
    {
        $texts = [];

        foreach ($payload['output'] ?? [] as $output) {
            if (! is_array($output)) {
                continue;
            }

            foreach ($output['content'] ?? [] as $content) {
                if (! is_array($content)) {
                    continue;
                }

                if (($content['type'] ?? null) === 'refusal') {
                    throw $this->invalidResponse();
                }

                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $texts[] = $content['text'];
                }
            }
        }

        $text = implode('', $texts);

        if (trim($text) === '') {
            throw $this->invalidResponse();
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function requestBody(array $source, string $locale): array
    {
        return [
            'model' => (string) config('cv_summary.openai.model', 'gpt-5-mini'),
            'store' => false,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->prompt->text($locale),
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'application_cv_summary',
                    'strict' => true,
                    'schema' => $this->schema->definition(),
                ],
            ],
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
