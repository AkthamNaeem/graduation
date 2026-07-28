<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlJobProfessionalFacts
{
    /**
     * @param  list<string>  $responsibilities
     * @param  list<MlRequiredSkill>  $requiredSkills
     * @param  list<string>  $niceToHaveSkills
     */
    public function __construct(
        public ?string $domain = null,
        public ?string $title = null,
        public ?string $department = null,
        public ?string $description = null,
        public array $responsibilities = [],
        public array $requiredSkills = [],
        public array $niceToHaveSkills = [],
        public ?float $minimumExperienceYears = null,
        public ?string $educationLevel = null,
        public ?string $careerLevel = null,
        public ?string $workMode = null,
        public ?string $employmentType = null,
    ) {
        foreach ([
            [$domain, 128],
            [$title, 256],
            [$department, 128],
            [$description, 4000],
            [$educationLevel, 128],
            [$careerLevel, 128],
            [$workMode, 128],
            [$employmentType, 128],
        ] as [$value, $maximum]) {
            if ($value !== null) {
                MlDataValidator::string($value, $maximum);
            }
        }
        if ($minimumExperienceYears !== null) {
            MlDataValidator::finiteNumber($minimumExperienceYears, 0, 100);
        }
        if (! array_is_list($responsibilities) || count($responsibilities) > 50) {
            MlDataValidator::requestFailure();
        }
        foreach ($responsibilities as $responsibility) {
            MlDataValidator::string($responsibility, 500);
        }
        if (! array_is_list($requiredSkills) || count($requiredSkills) > 100) {
            MlDataValidator::requestFailure();
        }
        foreach ($requiredSkills as $requiredSkill) {
            if (! $requiredSkill instanceof MlRequiredSkill) {
                MlDataValidator::requestFailure();
            }
        }
        if (! array_is_list($niceToHaveSkills) || count($niceToHaveSkills) > 100) {
            MlDataValidator::requestFailure();
        }
        foreach ($niceToHaveSkills as $niceToHaveSkill) {
            if (! is_string($niceToHaveSkill)
                || MlDataValidator::normalizeSkillName($niceToHaveSkill) !== $niceToHaveSkill) {
                MlDataValidator::requestFailure('ML_SKILL_NAME_NOT_NORMALIZED');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        MlDataValidator::requestKeys($data, [
            'domain',
            'title',
            'department',
            'description',
            'responsibilities',
            'required_skills',
            'nice_to_have_skills',
            'minimum_experience_years',
            'education_level',
            'career_level',
            'work_mode',
            'employment_type',
        ], []);

        $required = self::requiredSkills($data['required_skills'] ?? []);
        $nice = self::niceToHaveSkills(
            $data['nice_to_have_skills'] ?? [],
            array_map(fn (MlRequiredSkill $skill): string => $skill->name, $required),
        );

        return new self(
            domain: MlDataValidator::nullableString($data['domain'] ?? null, 128),
            title: MlDataValidator::nullableString($data['title'] ?? null, 256),
            department: MlDataValidator::nullableString($data['department'] ?? null, 128),
            description: MlDataValidator::nullableString($data['description'] ?? null, 4000),
            responsibilities: MlDataValidator::stringList(
                $data['responsibilities'] ?? [],
                50,
                500,
            ),
            requiredSkills: $required,
            niceToHaveSkills: $nice,
            minimumExperienceYears: isset($data['minimum_experience_years'])
                ? MlDataValidator::finiteNumber($data['minimum_experience_years'], 0, 100)
                : null,
            educationLevel: MlDataValidator::nullableString(
                $data['education_level'] ?? null,
                128,
            ),
            careerLevel: MlDataValidator::nullableString($data['career_level'] ?? null, 128),
            workMode: MlDataValidator::nullableString($data['work_mode'] ?? null, 128),
            employmentType: MlDataValidator::nullableString(
                $data['employment_type'] ?? null,
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
            'domain' => $this->domain,
            'title' => $this->title,
            'department' => $this->department,
            'description' => $this->description,
            'responsibilities' => $this->responsibilities,
            'required_skills' => array_map(
                fn (MlRequiredSkill $skill): array => $skill->toArray(),
                $this->requiredSkills,
            ),
            'nice_to_have_skills' => $this->niceToHaveSkills,
            'minimum_experience_years' => $this->minimumExperienceYears,
            'education_level' => $this->educationLevel,
            'career_level' => $this->careerLevel,
            'work_mode' => $this->workMode,
            'employment_type' => $this->employmentType,
        ];
    }

    /**
     * @return list<MlRequiredSkill>
     */
    private static function requiredSkills(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
            MlDataValidator::requestFailure();
        }

        /** @var array<string, float|null> $merged */
        $merged = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                MlDataValidator::requestFailure();
            }
            $skill = MlRequiredSkill::fromArray($item);
            if (! array_key_exists($skill->name, $merged)) {
                $merged[$skill->name] = $skill->weight;
            } elseif ($skill->weight !== null) {
                $merged[$skill->name] = $merged[$skill->name] === null
                    ? $skill->weight
                    : max($merged[$skill->name], $skill->weight);
            }
        }
        ksort($merged, SORT_STRING);

        $result = [];
        foreach ($merged as $name => $weight) {
            $result[] = new MlRequiredSkill($name, $weight);
        }

        return $result;
    }

    /**
     * @param  list<string>  $requiredNames
     * @return list<string>
     */
    private static function niceToHaveSkills(mixed $value, array $requiredNames): array
    {
        $skills = MlDataValidator::stringList($value, 100, 128);
        $required = array_fill_keys($requiredNames, true);
        $normalized = [];
        foreach ($skills as $skill) {
            $name = MlDataValidator::normalizeSkillName($skill);
            if (! isset($required[$name])) {
                $normalized[$name] = true;
            }
        }
        ksort($normalized, SORT_STRING);

        return array_keys($normalized);
    }
}
