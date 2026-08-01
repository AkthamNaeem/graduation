<?php

namespace Tests\Unit;

use App\Models\Experience;
use App\Services\ProfileExperienceCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProfileExperienceCalculatorTest extends TestCase
{
    public function test_it_returns_zero_without_experiences(): void
    {
        $this->assertSame(0.0, $this->calculator()->years(collect()));
    }

    public function test_it_calculates_one_completed_year(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2023-01-01', '2024-01-01'),
        ]));

        $this->assertSame(1.0, $years);
    }

    public function test_current_experience_uses_today_as_its_temporary_end(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2023-07-01', null, true),
        ]), Carbon::parse('2025-07-01'));

        $this->assertSame(2.0, $years);
    }

    public function test_it_adds_non_overlapping_experiences(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2020-01-01', '2021-01-01'),
            $this->experience('2022-01-01', '2023-01-01'),
        ]));

        $this->assertSame(2.0, $years);
    }

    public function test_it_does_not_double_count_overlapping_experiences(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2020-01-01', '2023-01-01'),
            $this->experience('2021-01-01', '2022-01-01'),
        ]));

        $this->assertSame(3.0, $years);
    }

    public function test_it_merges_connected_experiences(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2023-01-01', '2023-06-30'),
            $this->experience('2023-07-01', '2024-01-01'),
        ]));

        $this->assertSame(1.0, $years);
    }

    public function test_reversed_interval_never_produces_negative_experience(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2024-01-01', '2023-01-01'),
        ]));

        $this->assertSame(0.0, $years);
    }

    public function test_experience_without_a_start_date_is_ignored(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience(null, '2024-01-01'),
        ]));

        $this->assertSame(0.0, $years);
    }

    public function test_result_is_rounded_to_one_decimal(): void
    {
        $years = $this->calculator()->years(collect([
            $this->experience('2020-01-01', '2021-07-01'),
        ]));

        $this->assertSame(1.5, $years);
    }

    private function calculator(): ProfileExperienceCalculator
    {
        return new ProfileExperienceCalculator;
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
