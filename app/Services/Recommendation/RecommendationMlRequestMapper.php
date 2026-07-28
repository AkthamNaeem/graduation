<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationMlRequestMapperContract;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\RecommendationMl\MlCandidateProfessionalFacts;
use App\Data\RecommendationMl\MlCandidateSkill;
use App\Data\RecommendationMl\MlClientConfiguration;
use App\Data\RecommendationMl\MlJobProfessionalFacts;
use App\Data\RecommendationMl\MlRankJob;
use App\Data\RecommendationMl\MlRankRequest;
use App\Data\RecommendationMl\MlRequiredSkill;
use App\Enums\JobSkillRequirementType;
use App\Enums\JobWorkMode;
use App\Exceptions\Recommendation\RecommendationMappingException;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Services\CandidateExperienceCalculator;
use App\Services\EducationLevelNormalizer;
use App\Support\RecommendationMl\MlDataValidator;
use Illuminate\Support\Str;
use Throwable;

final readonly class RecommendationMlRequestMapper implements RecommendationMlRequestMapperContract
{
    /**
     * @param  array<string, int|float>  $experienceLevels
     */
    public function __construct(
        private CandidateExperienceCalculator $experienceCalculator,
        private EducationLevelNormalizer $educationNormalizer,
        private array $experienceLevels,
    ) {}

    public function map(
        RecommendationEligibility $eligibility,
        int $requestedLimit,
        MlClientConfiguration $configuration,
    ): MlRankRequest {
        try {
            $candidate = $this->candidateFacts($eligibility);
            $jobs = array_map(
                fn (JobPosting $job): MlRankJob => new MlRankJob(
                    (int) $job->id,
                    $this->jobFacts($job),
                ),
                $eligibility->jobs,
            );

            return new MlRankRequest(
                requestId: (string) Str::uuid(),
                featureSchemaVersion: $configuration->featureSchemaVersion,
                candidateProfessionalFacts: $candidate,
                jobs: $jobs,
                limit: min(
                    $requestedLimit,
                    $configuration->maxResults,
                    count($jobs),
                ),
                profileRef: null,
            );
        } catch (RecommendationMappingException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RecommendationMappingException;
        }
    }

    private function candidateFacts(
        RecommendationEligibility $eligibility,
    ): MlCandidateProfessionalFacts {
        $profile = $eligibility->profile;
        $this->assertProfileRelationsLoaded($profile);

        $skills = [];
        foreach ($profile->skills as $skill) {
            $name = MlDataValidator::normalizeSkillName((string) $skill->name);
            $skills[$name] = new MlCandidateSkill($name);
        }
        ksort($skills, SORT_STRING);

        return new MlCandidateProfessionalFacts(
            primaryDomain: null,
            adjacentDomains: [],
            headline: $this->optionalString($profile->headline),
            careerLevel: null,
            totalExperienceYears: $this->experienceCalculator->years(
                $profile->experiences,
                $eligibility->now,
            ),
            educationLevel: $this->educationNormalizer
                ->highest($profile->education)?->value,
            skills: array_values($skills),
            preferredWorkModes: [],
            preferredEmploymentTypes: [],
        );
    }

    private function jobFacts(JobPosting $job): MlJobProfessionalFacts
    {
        if (! $job->relationLoaded('company') || ! $job->relationLoaded('skills')) {
            throw new RecommendationMappingException;
        }

        [$careerLevel, $minimumExperienceYears] = $this->experienceFacts(
            $job->experience_level,
        );
        [$requiredSkills, $niceToHaveSkills] = $this->jobSkills($job);

        return new MlJobProfessionalFacts(
            domain: null,
            title: $this->optionalString($job->title),
            department: $this->optionalString($job->department),
            description: $this->optionalString($job->description),
            responsibilities: $this->responsibilities($job->responsibilities),
            requiredSkills: $requiredSkills,
            niceToHaveSkills: $niceToHaveSkills,
            minimumExperienceYears: $minimumExperienceYears,
            educationLevel: $this->optionalString($job->education_level),
            careerLevel: $careerLevel,
            workMode: $this->workMode($job->work_mode),
            employmentType: $this->normalizedCategory($job->employment_type),
        );
    }

    /**
     * @return array{0: string|null, 1: float|null}
     */
    private function experienceFacts(mixed $value): array
    {
        $level = Str::lower(trim((string) $value));
        if ($level === '') {
            return [null, null];
        }
        if (! array_key_exists($level, $this->experienceLevels)) {
            throw new RecommendationMappingException;
        }

        $careerLevel = match ($level) {
            'entry-level' => 'entry',
            'mid-level' => 'mid',
            default => str_replace(['-', ' '], '_', $level),
        };

        return [$careerLevel, (float) $this->experienceLevels[$level]];
    }

    /**
     * @return array{0: list<MlRequiredSkill>, 1: list<string>}
     */
    private function jobSkills(JobPosting $job): array
    {
        /** @var array<string, float> $required */
        $required = [];
        /** @var array<string, true> $nice */
        $nice = [];

        foreach ($job->skills as $skill) {
            $rawType = $skill->pivot->requirement_type;
            $type = JobSkillRequirementType::normalize(
                $rawType instanceof JobSkillRequirementType
                    ? $rawType->value
                    : (string) $rawType,
            );
            $name = MlDataValidator::normalizeSkillName((string) $skill->name);
            $weight = (int) $skill->pivot->weight;
            if ($type === null || $weight < 1 || $weight > 5) {
                throw new RecommendationMappingException;
            }

            if ($type->isRequired()) {
                $required[$name] = max($required[$name] ?? 0, $weight);
                unset($nice[$name]);
            } elseif (! array_key_exists($name, $required)) {
                $nice[$name] = true;
            }
        }

        ksort($required, SORT_STRING);
        ksort($nice, SORT_STRING);

        return [
            array_map(
                static fn (string $name, float $weight): MlRequiredSkill => new MlRequiredSkill(
                    $name,
                    $weight,
                ),
                array_keys($required),
                array_values($required),
            ),
            array_keys($nice),
        ];
    }

    /**
     * @return list<string>
     */
    private function responsibilities(mixed $value): array
    {
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\R+/u', $text) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $lines,
        ), static fn (string $line): bool => $line !== ''));
    }

    private function workMode(mixed $value): ?string
    {
        $mode = $value instanceof JobWorkMode ? $value->value : (string) $value;
        $mode = $this->normalizedCategory($mode);

        return $mode === 'on_site' ? 'onsite' : $mode;
    }

    private function normalizedCategory(mixed $value): ?string
    {
        $value = Str::lower(trim((string) $value));

        return $value === '' ? null : str_replace(['-', ' '], '_', $value);
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertProfileRelationsLoaded(JobSeekerProfile $profile): void
    {
        foreach (['skills', 'experiences', 'education'] as $relation) {
            if (! $profile->relationLoaded($relation)) {
                throw new RecommendationMappingException;
            }
        }
    }
}
