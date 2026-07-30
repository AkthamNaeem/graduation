<?php

namespace App\Support\Recommendation;

class RecommendationReasonTranslator
{
    /**
     * @param  array<string, mixed>  $reason
     */
    public static function translate(array $reason): array
    {
        $code = (string) ($reason['code'] ?? '');
        $key = 'ai.reasons.'.$code;
        if (! __($key) || __($key) === $key) {
            return $reason;
        }

        $numbers = [];
        preg_match_all('/\d+(?:\.\d+)?/', (string) ($reason['message'] ?? ''), $matches);
        $numbers = $matches[0] ?? [];

        $replacements = match ($code) {
            'REQUIRED_SKILLS_MATCH' => ['matched' => $numbers[0] ?? 0, 'total' => $numbers[1] ?? 0],
            'MISSING_REQUIRED_SKILLS', 'NICE_TO_HAVE_SKILLS_MATCH' => ['count' => $numbers[0] ?? 0],
            'EXPERIENCE_MATCH' => ['candidate' => $numbers[0] ?? 0, 'required' => $numbers[1] ?? 0],
            default => [],
        };

        return [...$reason, 'message' => __($key, $replacements)];
    }
}
