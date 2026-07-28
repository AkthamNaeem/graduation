<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlCandidateSkill
{
    public function __construct(
        public string $name,
        public ?float $proficiency = null,
        public ?float $yearsExperience = null,
    ) {
        $normalizedName = MlDataValidator::normalizeSkillName($name);
        if ($normalizedName !== $name) {
            MlDataValidator::requestFailure('ML_SKILL_NAME_NOT_NORMALIZED');
        }
        if ($proficiency !== null) {
            MlDataValidator::finiteNumber($proficiency, 0, 5);
        }
        if ($yearsExperience !== null) {
            MlDataValidator::finiteNumber($yearsExperience, 0, 100);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        MlDataValidator::requestKeys(
            $data,
            ['name', 'proficiency', 'years_experience'],
            ['name'],
        );

        return new self(
            name: MlDataValidator::normalizeSkillName(
                MlDataValidator::string($data['name'], 128),
            ),
            proficiency: array_key_exists('proficiency', $data) && $data['proficiency'] !== null
                ? MlDataValidator::finiteNumber($data['proficiency'], 0, 5)
                : null,
            yearsExperience: array_key_exists('years_experience', $data)
                && $data['years_experience'] !== null
                    ? MlDataValidator::finiteNumber($data['years_experience'], 0, 100)
                    : null,
        );
    }

    /**
     * @return array{name: string, proficiency: float|null, years_experience: float|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'proficiency' => $this->proficiency,
            'years_experience' => $this->yearsExperience,
        ];
    }
}
