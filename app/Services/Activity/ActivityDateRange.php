<?php

namespace App\Services\Activity;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final readonly class ActivityDateRange
{
    public function __construct(
        public string $timezone,
        public CarbonImmutable $todayStart,
        public CarbonImmutable $todayEnd,
        public CarbonImmutable $weekStart,
        public CarbonImmutable $weekEnd,
    ) {}

    public static function make(?string $timezone = null): self
    {
        $timezone ??= (string) config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        return new self(
            $timezone,
            $now->startOfDay()->utc(),
            $now->endOfDay()->utc(),
            $now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay()->utc(),
            $now->endOfWeek(CarbonImmutable::SUNDAY)->endOfDay()->utc(),
        );
    }

    public function isToday(DateTimeInterface|string|null ...$values): bool
    {
        return $this->contains($this->todayStart, $this->todayEnd, ...$values);
    }

    public function isThisWeek(DateTimeInterface|string|null ...$values): bool
    {
        return $this->contains($this->weekStart, $this->weekEnd, ...$values);
    }

    private function contains(CarbonImmutable $start, CarbonImmutable $end, DateTimeInterface|string|null ...$values): bool
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $date = CarbonImmutable::parse($value)->utc();
            if ($date->betweenIncluded($start, $end)) {
                return true;
            }
        }

        return false;
    }
}
