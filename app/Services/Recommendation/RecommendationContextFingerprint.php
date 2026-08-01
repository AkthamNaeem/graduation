<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationContextFingerprintContract;
use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;
use App\Enums\JobSkillRequirementType;
use App\Services\CandidateExperienceCalculator;
use BackedEnum;
use DateTimeInterface;

final readonly class RecommendationContextFingerprint implements RecommendationContextFingerprintContract
{
    /**
     * @param  array<string, mixed>  $matchingConfiguration
     * @param  array<string, mixed>  $mlConfiguration
     */
    public function __construct(
        private CandidateExperienceCalculator $experienceCalculator,
        private array $matchingConfiguration,
        private array $mlConfiguration,
        private string $contextVersion,
        private string $rankingPolicyVersion,
    ) {}

    public function fingerprint(
        RecommendationEligibility $eligibility,
        bool $mlEnabled,
    ): RecommendationContext {
        $profile = $eligibility->profile;
        foreach (['skills', 'experiences', 'education'] as $relation) {
            if (! $profile->relationLoaded($relation)) {
                throw new \InvalidArgumentException(
                    'Recommendation fingerprint requires loaded profile relations.',
                );
            }
        }

        $candidateSkills = $profile->skills
            ->map(fn ($skill): array => [
                'id' => (int) $skill->id,
                'name' => (string) $skill->name,
            ])
            ->sortBy([['id', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
        $experiences = $profile->experiences
            ->map(fn ($experience): array => [
                'title' => $experience->title,
                'description' => $experience->description,
                'start_date' => $this->date($experience->start_date),
                'end_date' => $this->date($experience->end_date),
                'is_current' => (bool) $experience->is_current,
            ])
            ->sortBy(fn (array $value): string => $this->sortValue($value))
            ->values()
            ->all();
        $education = $profile->education
            ->map(fn ($entry): array => [
                'institution' => $entry->institution,
                'degree' => $entry->degree,
                'field_of_study' => $entry->field_of_study,
                'description' => $entry->description,
            ])
            ->sortBy(fn (array $value): string => $this->sortValue($value))
            ->values()
            ->all();

        $jobs = [];
        foreach ($eligibility->jobs as $job) {
            if (! $job->relationLoaded('company') || ! $job->relationLoaded('skills')) {
                throw new \InvalidArgumentException(
                    'Recommendation fingerprint requires loaded job relations.',
                );
            }
            $skills = $job->skills
                ->map(function ($skill): array {
                    $rawType = $skill->pivot->requirement_type;
                    $type = JobSkillRequirementType::normalize(
                        $rawType instanceof JobSkillRequirementType
                            ? $rawType->value
                            : (string) $rawType,
                    );

                    return [
                        'id' => (int) $skill->id,
                        'name' => (string) $skill->name,
                        'requirement_type' => $type?->value,
                        'weight' => (int) $skill->pivot->weight,
                    ];
                })
                ->sortBy([
                    ['id', 'asc'],
                    ['requirement_type', 'asc'],
                    ['weight', 'asc'],
                ])
                ->values()
                ->all();
            $jobs[] = [
                'id' => (int) $job->id,
                'title' => $job->title,
                'department' => $job->department,
                'description' => $job->description,
                'responsibilities' => $job->responsibilities,
                'requirements' => $job->requirements,
                'employment_type' => $job->employment_type,
                'experience_level' => $job->experience_level,
                'education_level' => $job->education_level,
                'work_mode' => $job->work_mode,
                'city_id' => $job->city_id,
                'status' => $job->status,
                'published_at' => $this->date($job->published_at),
                'application_deadline' => $this->date($job->application_deadline),
                'company_approval_status' => $job->company?->approval_status,
                'skills' => $skills,
            ];
        }
        usort(
            $jobs,
            static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
        );

        $input = [
            'candidate' => [
                'headline' => $profile->headline,
                'summary' => $profile->summary,
                'city_id' => $profile->city_id,
                'total_experience_years' => $this->experienceCalculator->years(
                    $profile->experiences,
                    $eligibility->now,
                ),
                'experiences' => $experiences,
                'education' => $education,
                'skills' => $candidateSkills,
            ],
            'eligible_jobs' => $jobs,
            'configuration' => [
                'context_version' => $this->contextVersion,
                'ml_enabled' => $mlEnabled,
                'matching_version' => $this->matchingConfiguration['version'] ?? null,
                'matching_components' => $this->matchingConfiguration['components'] ?? [],
                'matching_experience_levels' => (
                    $this->matchingConfiguration['experience_levels'] ?? []
                ),
                'model_version' => $this->mlConfiguration['model_version'] ?? null,
                'feature_schema_version' => (
                    $this->mlConfiguration['feature_schema_version'] ?? null
                ),
                'explanation_contract_version' => (
                    $this->mlConfiguration['explanation_contract_version'] ?? null
                ),
                'score_transform_version' => (
                    $this->mlConfiguration['score_transform_version'] ?? null
                ),
                'max_jobs_per_request' => (
                    $this->mlConfiguration['max_jobs_per_request'] ?? null
                ),
                'max_results' => $this->mlConfiguration['max_results'] ?? null,
                'final_ranking_policy_version' => $this->rankingPolicyVersion,
            ],
        ];
        $canonical = json_encode(
            $this->canonicalize($input),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );

        return new RecommendationContext(
            hash('sha256', $canonical),
            $this->contextVersion,
        );
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d\TH:i:s.uP')
            : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function sortValue(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_float($value)) {
            $formatted = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');

            return $formatted === '-0' ? '0' : $formatted;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
