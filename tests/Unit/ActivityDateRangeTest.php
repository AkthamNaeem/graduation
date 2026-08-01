<?php

namespace Tests\Unit;

use App\Services\Activity\ActivityDateRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ActivityDateRangeTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_week_runs_from_local_monday_through_sunday_and_converts_to_utc(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 12:00:00', 'Asia/Damascus'));

        $range = ActivityDateRange::make('Asia/Damascus');

        $this->assertSame('2026-07-26T21:00:00.000000Z', $range->weekStart->toISOString());
        $this->assertSame('2026-08-02T20:59:59.999999Z', $range->weekEnd->toISOString());
        $this->assertTrue($range->isToday('2026-08-01T00:30:00+03:00'));
        $this->assertFalse($range->isToday('2026-07-31T23:30:00+03:00'));
        $this->assertTrue($range->isThisWeek('2026-08-02T23:59:59+03:00'));
        $this->assertFalse($range->isThisWeek('2026-08-03T00:00:00+03:00'));
    }
}
