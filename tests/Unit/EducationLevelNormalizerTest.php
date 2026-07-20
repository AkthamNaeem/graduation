<?php

namespace Tests\Unit;

use App\Enums\EducationLevel;
use App\Models\Education;
use App\Services\EducationLevelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationLevelNormalizerTest extends TestCase
{
    #[DataProvider('degreeCases')]
    public function test_it_normalizes_only_explicit_known_degrees(string $degree, ?EducationLevel $expected): void
    {
        $this->assertSame($expected, (new EducationLevelNormalizer)->normalize($degree));
    }

    public static function degreeCases(): array
    {
        return [
            'doctorate' => ['PhD in Computing', EducationLevel::DOCTORATE],
            'master case insensitive' => ['MASTER OF SCIENCE', EducationLevel::MASTER],
            'bachelor abbreviation' => ['B.Sc. Computer Science', EducationLevel::BACHELOR],
            'diploma' => ['Associate Degree', EducationLevel::DIPLOMA],
            'high school' => ['Secondary School', EducationLevel::HIGH_SCHOOL],
            'unknown' => ['Software Engineering Program', null],
        ];
    }

    public function test_it_selects_the_highest_known_education_entry(): void
    {
        $entries = collect([
            new Education(['degree' => 'Bachelor of Science']),
            new Education(['degree' => 'Doctorate']),
            new Education(['degree' => 'Unknown Certificate']),
        ]);

        $this->assertSame(EducationLevel::DOCTORATE, (new EducationLevelNormalizer)->highest($entries));
    }
}
