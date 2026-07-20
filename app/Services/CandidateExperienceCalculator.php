<?php

namespace App\Services;

use App\Models\Experience;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CandidateExperienceCalculator
{
    /** @param Collection<int, Experience> $experiences */
    public function years(Collection $experiences, ?Carbon $asOf = null): float
    {
        $asOf ??= now();
        $intervals = $experiences
            ->map(function (Experience $experience) use ($asOf): ?array {
                $start = $experience->start_date?->copy()->startOfDay();
                $end = $experience->is_current
                    ? $asOf->copy()->startOfDay()
                    : $experience->end_date?->copy()->startOfDay();

                if ($start === null || $end === null || $end->lt($start)) {
                    return null;
                }

                return [$start, $end];
            })
            ->filter()
            ->sortBy(fn (array $interval): int => $interval[0]->getTimestamp())
            ->values();

        if ($intervals->isEmpty()) {
            return 0.0;
        }

        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex < 0 || $start->gt($merged[$lastIndex][1])) {
                $merged[] = [$start, $end];

                continue;
            }

            if ($end->gt($merged[$lastIndex][1])) {
                $merged[$lastIndex][1] = $end;
            }
        }

        $days = array_sum(array_map(
            static fn (array $interval): int => (int) $interval[0]->diffInDays($interval[1]),
            $merged,
        ));

        return round($days / 365.25, 2);
    }
}
