<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlCandidateProfessionalFacts
{
    /**
     * @param  list<string>  $adjacentDomains
     * @param  list<MlCandidateSkill>  $skills
     * @param  list<string>  $preferredWorkModes
     * @param  list<string>  $preferredEmploymentTypes
     */
    public function __construct(
        public ?string $primaryDomain = null,
        public array $adjacentDomains = [],
        public ?string $headline = null,
        public ?string $careerLevel = null,
        public ?float $totalExperienceYears = null,
        public ?string $educationLevel = null,
        public array $skills = [],
        public array $preferredWorkModes = [],
        public array $preferredEmploymentTypes = [],
    ) {
        if ($primaryDomain !== null) {
            MlDataValidator::string($primaryDomain, 128);
        }
        if ($headline !== null) {
            MlDataValidator::string($headline, 256);
        }
        if ($careerLevel !== null) {
            MlDataValidator::string($careerLevel, 128);
        }
        if ($educationLevel !== null) {
            MlDataValidator::string($educationLevel, 128);
        }
        if ($totalExperienceYears !== null) {
            MlDataValidator::finiteNumber($totalExperienceYears, 0, 100);
        }
        self::assertStrings($adjacentDomains, 20, 128);
        self::assertStrings($preferredWorkModes, 10, 128);
        self::assertStrings($preferredEmploymentTypes, 10, 128);
        if (! array_is_list($skills) || count($skills) > 100) {
            MlDataValidator::requestFailure();
        }
        foreach ($skills as $skill) {
            if (! $skill instanceof MlCandidateSkill) {
                MlDataValidator::requestFailure();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        MlDataValidator::requestKeys($data, [
            'primary_domain',
            'adjacent_domains',
            'headline',
            'career_level',
            'total_experience_years',
            'education_level',
            'skills',
            'preferred_work_modes',
            'preferred_employment_types',
        ], []);

        $skills = self::skills($data['skills'] ?? []);

        return new self(
            primaryDomain: MlDataValidator::nullableString($data['primary_domain'] ?? null, 128),
            adjacentDomains: MlDataValidator::stringList(
                $data['adjacent_domains'] ?? [],
                20,
                128,
            ),
            headline: MlDataValidator::nullableString($data['headline'] ?? null, 256),
            careerLevel: MlDataValidator::nullableString($data['career_level'] ?? null, 128),
            totalExperienceYears: isset($data['total_experience_years'])
                ? MlDataValidator::finiteNumber($data['total_experience_years'], 0, 100)
                : null,
            educationLevel: MlDataValidator::nullableString(
                $data['education_level'] ?? null,
                128,
            ),
            skills: $skills,
            preferredWorkModes: MlDataValidator::stringList(
                $data['preferred_work_modes'] ?? [],
                10,
                128,
            ),
            preferredEmploymentTypes: MlDataValidator::stringList(
                $data['preferred_employment_types'] ?? [],
                10,
                128,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primary_domain' => $this->primaryDomain,
            'adjacent_domains' => $this->adjacentDomains,
            'headline' => $this->headline,
            'career_level' => $this->careerLevel,
            'total_experience_years' => $this->totalExperienceYears,
            'education_level' => $this->educationLevel,
            'skills' => array_map(
                fn (MlCandidateSkill $skill): array => $skill->toArray(),
                $this->skills,
            ),
            'preferred_work_modes' => $this->preferredWorkModes,
            'preferred_employment_types' => $this->preferredEmploymentTypes,
        ];
    }

    /**
     * @return list<MlCandidateSkill>
     */
    private static function skills(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
            MlDataValidator::requestFailure();
        }

        /** @var array<string, array{proficiency: float|null, years: float|null}> $merged */
        $merged = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                MlDataValidator::requestFailure();
            }
            $skill = MlCandidateSkill::fromArray($item);
            $existing = $merged[$skill->name] ?? ['proficiency' => null, 'years' => null];
            $merged[$skill->name] = [
                'proficiency' => self::maximumNullable(
                    $existing['proficiency'],
                    $skill->proficiency,
                ),
                'years' => self::maximumNullable($existing['years'], $skill->yearsExperience),
            ];
        }

        ksort($merged, SORT_STRING);

        return array_values(array_map(
            fn (string $name, array $values): MlCandidateSkill => new MlCandidateSkill(
                name: $name,
                proficiency: $values['proficiency'],
                yearsExperience: $values['years'],
            ),
            array_keys($merged),
            array_values($merged),
        ));
    }

    private static function maximumNullable(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return max($left, $right);
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function assertStrings(array $values, int $maximumItems, int $maximumLength): void
    {
        if (! array_is_list($values) || count($values) > $maximumItems) {
            MlDataValidator::requestFailure();
        }
        foreach ($values as $value) {
            MlDataValidator::string($value, $maximumLength);
        }
    }
}
