<?php

namespace App\Support\RecommendationMl;

use App\Exceptions\RecommendationMl\MlRecommendationValidationException;

final class MlOutboundPayloadGuard
{
    private const SENSITIVE_KEYS = [
        'name',
        'full_name',
        'email',
        'phone',
        'birth_date',
        'date_of_birth',
        'age',
        'gender',
        'sex',
        'nationality',
        'marital_status',
        'personal_address',
        'address',
        'cv_file',
        'cv_path',
        'raw_cv',
        'raw_cv_text',
        'parsed_cv_json',
        'cover_letter',
        'screening_answers',
        'application_status',
        'application_history',
        'test_results',
        'interview_results',
        'internal_notes',
        'auth_token',
        'sanctum_token',
        'cookie',
        'cookies',
        'session',
        'password',
        'secret',
        'db_password',
        'database_url',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertSafe(array $payload, ?string $requestId = null): void
    {
        $this->scan($payload, [], $requestId);
    }

    /**
     * @param  list<string>  $path
     */
    private function scan(mixed $value, array $path, ?string $requestId): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $rawKey => $nested) {
            $key = is_string($rawKey) ? $this->normalizeKey($rawKey) : '*';
            $childPath = [...$path, $key];

            if (in_array($key, self::SENSITIVE_KEYS, true)
                && ! ($key === 'name' && $this->isApprovedSkillNamePath($childPath))) {
                throw new MlRecommendationValidationException(
                    internalCode: 'ML_SENSITIVE_FIELD_NOT_ALLOWED',
                    requestId: $requestId,
                    operation: 'rank',
                );
            }

            $this->scan($nested, $childPath, $requestId);
        }
    }

    /**
     * @param  list<string>  $path
     */
    private function isApprovedSkillNamePath(array $path): bool
    {
        $joined = implode('.', $path);

        return preg_match(
            '/^(candidate\.professional_facts\.skills\.\*\.name|'
            .'jobs\.\*\.professional_facts\.required_skills\.\*\.name)$/D',
            $joined,
        ) === 1;
    }

    private function normalizeKey(string $key): string
    {
        return str_replace(['-', ' '], '_', mb_strtolower(trim($key)));
    }
}
