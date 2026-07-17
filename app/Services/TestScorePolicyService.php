<?php

namespace App\Services;

use App\Exceptions\TestScorePolicyException;
use App\Models\Test;
use InvalidArgumentException;

class TestScorePolicyService
{
    public function calculateCanonicalMaxScore(Test $test): string
    {
        $minor = $test->questions()->get(['points'])->reduce(
            fn (int $sum, $question): int => $sum + $this->toMinorUnits((string) $question->points),
            0,
        );

        return $this->fromMinorUnits($minor);
    }

    public function synchronizeMaxScore(Test $test): string
    {
        $canonical = $this->calculateCanonicalMaxScore($test);
        if ($this->toMinorUnits((string) $test->max_score) !== $this->toMinorUnits($canonical)) {
            $test->forceFill(['max_score' => $canonical])->save();
        }

        return $canonical;
    }

    public function validatePassingScore(Test $test, int|float|string|null $passingScore = null, bool $useProvided = false): void
    {
        $passing = $useProvided ? $passingScore : $test->passing_score;
        if ($passing === null || $passing === '') {
            return;
        }

        $canonical = $this->calculateCanonicalMaxScore($test);
        if ($this->toMinorUnits($passing) < 0 || $this->toMinorUnits($passing) > $this->toMinorUnits($canonical)) {
            throw new TestScorePolicyException(
                'The passing score may not be greater than the test maximum score.',
                'TEST_PASSING_SCORE_EXCEEDS_MAX_SCORE',
                422,
                ['passing_score' => ['The passing score may not be greater than the test maximum score.']],
            );
        }
    }

    public function assertScoreConfigurationValid(Test $test, bool $requireScoreable = false): void
    {
        try {
            $canonical = $this->synchronizeMaxScore($test);
            foreach ($test->questions()->get(['points']) as $question) {
                if ($this->toMinorUnits((string) $question->points) < 0) {
                    throw new InvalidArgumentException('Question points cannot be negative.');
                }
            }
            if ($requireScoreable && ($test->questions()->count() === 0 || $this->toMinorUnits($canonical) <= 0)) {
                throw new TestScorePolicyException(
                    'The test must contain at least one question with a positive score before it can be assigned.',
                    'TEST_HAS_NO_SCOREABLE_QUESTIONS',
                    409,
                );
            }
            $passing = $test->passing_score;
            if ($passing !== null && ($this->toMinorUnits((string) $passing) < 0 || $this->toMinorUnits((string) $passing) > $this->toMinorUnits($canonical))) {
                throw new InvalidArgumentException('Passing score is outside the canonical range.');
            }
        } catch (InvalidArgumentException) {
            throw new TestScorePolicyException(
                'The test score configuration is invalid.',
                'TEST_SCORE_CONFIGURATION_INVALID',
                409,
            );
        }

    }

    public function assertAssignable(Test $test): void
    {
        $this->assertScoreConfigurationValid($test, requireScoreable: true);
    }

    /** @return array{max_score:string,passing_score:?string,passing_score_percentage:?float,question_count:int,score_configuration_valid:bool} */
    public function buildScoreConfiguration(Test $test): array
    {
        $canonical = $this->calculateCanonicalMaxScore($test);
        $maxMinor = $this->toMinorUnits($canonical);
        $passing = $test->passing_score;
        $passingMinor = $passing === null ? null : $this->toMinorUnits((string) $passing);

        return [
            'max_score' => $canonical,
            'passing_score' => $passing,
            'passing_score_percentage' => $passingMinor === null || $maxMinor === 0
                ? null
                : round(($passingMinor / $maxMinor) * 100, 2),
            'question_count' => $test->relationLoaded('questions') ? $test->questions->count() : $test->questions()->count(),
            'score_configuration_valid' => $maxMinor > 0 && ($passingMinor === null || ($passingMinor >= 0 && $passingMinor <= $maxMinor)),
        ];
    }

    public function toMinorUnits(int|float|string $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Invalid decimal score.');
        }

        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    public function fromMinorUnits(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function passingScoreMet(Test $test, int|float|string|null $totalScore, int|float|string|null $maxScore, bool $complete): ?bool
    {
        if (! $complete || $totalScore === null || $maxScore === null || $this->toMinorUnits($maxScore) <= 0 || $test->passing_score === null) {
            return null;
        }

        return $this->toMinorUnits($totalScore) >= $this->toMinorUnits((string) $test->passing_score);
    }
}
