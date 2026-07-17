<?php

namespace App\Services;

use App\Exceptions\TestAttemptTimingException;
use App\Models\ApplicationTestAssignment;
use App\Models\TestAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class TestAttemptTimingService
{
    public const MAX_DURATION_MINUTES = 1440;

    public function durationDeadline(TestAttempt $attempt): CarbonImmutable
    {
        if ($attempt->started_at === null) {
            throw new TestAttemptTimingException(
                'The start time for this test attempt is missing.',
                'TEST_ATTEMPT_START_TIME_MISSING',
            );
        }

        $assignment = $this->assignment($attempt);
        $duration = $assignment->test?->duration_minutes;
        if (! is_int($duration) || $duration < 1 || $duration > self::MAX_DURATION_MINUTES) {
            throw new TestAttemptTimingException(
                'The configured test duration is invalid.',
                'INVALID_TEST_DURATION',
            );
        }

        return $attempt->started_at->toImmutable()->utc()->addMinutes($duration);
    }

    public function calculateEffectiveDeadline(TestAttempt $attempt): CarbonImmutable
    {
        $durationDeadline = $this->durationDeadline($attempt);
        $assignmentDeadline = $this->assignment($attempt)->deadline_at?->toImmutable()->utc();

        return $assignmentDeadline !== null && $assignmentDeadline->lessThan($durationDeadline)
            ? $assignmentDeadline
            : $durationDeadline;
    }

    public function effectiveDeadline(TestAttempt $attempt): CarbonImmutable
    {
        return $attempt->effective_deadline_at?->toImmutable()->utc()
            ?? $this->calculateEffectiveDeadline($attempt);
    }

    public function snapshot(TestAttempt $attempt, bool $recalculate = false): CarbonImmutable
    {
        if ($attempt->effective_deadline_at !== null && ! $recalculate) {
            return $attempt->effective_deadline_at->toImmutable()->utc();
        }

        $deadline = $this->calculateEffectiveDeadline($attempt);
        $attempt->forceFill(['effective_deadline_at' => $deadline])->save();

        return $deadline;
    }

    public function isExpired(TestAttempt $attempt, Carbon|CarbonImmutable|null $at = null): bool
    {
        return ($at ?? now())->greaterThan($this->effectiveDeadline($attempt));
    }

    public function remainingSeconds(TestAttempt $attempt, Carbon|CarbonImmutable|null $at = null): int
    {
        return max(0, (int) ($at ?? now())->diffInSeconds($this->effectiveDeadline($attempt), false));
    }

    public function assertCanMutate(TestAttempt $attempt): void
    {
        $this->snapshot($attempt);
        if ($this->isExpired($attempt)) {
            throw new TestAttemptTimingException(
                'The allowed time for this test attempt has expired.',
                'TEST_ATTEMPT_TIME_EXPIRED',
            );
        }
    }

    private function assignment(TestAttempt $attempt): ApplicationTestAssignment
    {
        $attempt->loadMissing('applicationTestAssignment.test');

        return $attempt->applicationTestAssignment;
    }
}
