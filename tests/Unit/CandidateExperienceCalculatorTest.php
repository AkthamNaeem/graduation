<?php

namespace Tests\Unit;

use App\Models\Experience;
use App\Services\CandidateExperienceCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CandidateExperienceCalculatorTest extends TestCase
{
    public function test_it_calculates_one_completed_experience(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2020-01-01', '2022-01-01'),
        ]));

        $this->assertEqualsWithDelta(2, $years, 0.01);
    }

    public function test_it_adds_sequential_experiences(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2020-01-01', '2021-01-01'),
            $this->experience('2021-01-01', '2022-01-01'),
        ]));

        $this->assertEqualsWithDelta(2, $years, 0.01);
    }

    public function test_it_does_not_double_count_overlapping_experiences(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2020-01-01', '2023-01-01'),
            $this->experience('2021-01-01', '2022-01-01'),
        ]));

        $this->assertEqualsWithDelta(3, $years, 0.01);
    }

    public function test_current_experience_uses_the_supplied_current_date(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2023-07-01', null, true),
        ]), Carbon::parse('2025-07-01'));

        $this->assertEqualsWithDelta(2, $years, 0.01);
    }

    public function test_it_ignores_missing_start_and_reversed_intervals(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience(null, '2024-01-01'),
            $this->experience('2024-01-01', '2023-01-01'),
        ]));

        $this->assertSame(0.0, $years);
    }

    private function calculator(): CandidateExperienceCalculator
    {
        return new CandidateExperienceCalculator;
    }

    private function experience(?string $start, ?string $end, bool $current = false): Experience
    {
        return new Experience([
            'start_date' => $start,
            'end_date' => $end,
            'is_current' => $current,
        ]);
    }
}
