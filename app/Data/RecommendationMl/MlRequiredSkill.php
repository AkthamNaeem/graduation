<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlRequiredSkill
{
    public function __construct(
        public string $name,
        public ?float $weight = null,
    ) {
        if (MlDataValidator::normalizeSkillName($name) !== $name) {
            MlDataValidator::requestFailure('ML_SKILL_NAME_NOT_NORMALIZED');
        }
        if ($weight !== null) {
            MlDataValidator::finiteNumber($weight, 0, 5);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        MlDataValidator::requestKeys($data, ['name', 'weight'], ['name']);

        return new self(
            name: MlDataValidator::normalizeSkillName(
                MlDataValidator::string($data['name'], 128),
            ),
            weight: array_key_exists('weight', $data) && $data['weight'] !== null
                ? MlDataValidator::finiteNumber($data['weight'], 0, 5)
                : null,
        );
    }

    /**
     * @return array{name: string, weight: float|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'weight' => $this->weight,
        ];
    }
}
