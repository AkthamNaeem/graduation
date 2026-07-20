<?php

namespace App\Services;

use App\Enums\EducationLevel;
use App\Models\Education;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EducationLevelNormalizer
{
    public function normalize(?string $degree): ?EducationLevel
    {
        $value = Str::lower(trim((string) $degree));
        if ($value === '') {
            return null;
        }

        return match (true) {
            preg_match('/\b(ph\.?d|doctorate|doctor of)\b/u', $value) === 1 => EducationLevel::DOCTORATE,
            preg_match('/\b(master|m\.?sc|m\.?a)\b/u', $value) === 1 => EducationLevel::MASTER,
            preg_match('/\b(bachelor|b\.?sc|b\.?a)\b/u', $value) === 1 => EducationLevel::BACHELOR,
            preg_match('/\b(diploma|associate)\b/u', $value) === 1 => EducationLevel::DIPLOMA,
            preg_match('/\b(high school|secondary)\b/u', $value) === 1 => EducationLevel::HIGH_SCHOOL,
            default => null,
        };
    }

    /** @param Collection<int, Education> $education */
    public function highest(Collection $education): ?EducationLevel
    {
        return $education
            ->map(fn (Education $entry): ?EducationLevel => $this->normalize($entry->degree))
            ->filter()
            ->sortByDesc(fn (EducationLevel $level): int => $level->rank())
            ->first();
    }
}
