<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tests')->select(['id', 'passing_score'])->orderBy('id')->each(function (object $test): void {
            $minor = DB::table('test_questions')->where('test_id', $test->id)->pluck('points')
                ->reduce(fn (int $sum, mixed $points): int => $sum + $this->minor((string) $points), 0);
            $canonical = $this->formatMinor($minor);
            $passing = $test->passing_score === null ? null : $this->normalize((string) $test->passing_score);

            DB::table('tests')->where('id', $test->id)->update([
                'max_score' => $canonical,
                'passing_score' => $passing !== null && $this->minor($passing) > $this->minor($canonical) ? null : $passing,
            ]);
        });
    }

    public function down(): void
    {
        // Configuration normalization is intentionally not reversed.
    }

    private function normalize(string $value): string
    {
        return $this->formatMinor($this->minor($value));
    }

    private function formatMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    private function minor(string $value): int
    {
        preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($value), $matches);

        return ((int) ($matches[1] ?? 0) * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
};
