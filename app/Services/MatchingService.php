<?php

namespace App\Services;

use App\Enums\EducationLevel;
use App\Enums\JobSkillRequirementType;
use App\Enums\UserRole;
use App\Exceptions\JobPostingOperationException;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MatchingService
{
    private readonly CandidateExperienceCalculator $experienceCalculator;

    private readonly EducationLevelNormalizer $educationNormalizer;

    public function __construct(
        ?CandidateExperienceCalculator $experienceCalculator = null,
        ?EducationLevelNormalizer $educationNormalizer = null,
    ) {
        $this->experienceCalculator = $experienceCalculator ?? new CandidateExperienceCalculator;
        $this->educationNormalizer = $educationNormalizer ?? new EducationLevelNormalizer;
    }

    /** @return array<string, string> */
    public function buildTextFromProfile(JobSeekerProfile $profile): array
    {
        $profile->loadMissing(['skills', 'experiences', 'education']);

        return [
            'core' => $this->joinParts([$profile->headline, $profile->summary]),
            'skills' => $profile->skills->pluck('name')->filter()->implode(' '),
            'experience' => $this->joinParts($profile->experiences
                ->flatMap(fn ($experience): array => [$experience->title, $experience->description])
                ->all()),
            'education' => $this->joinParts($profile->education
                ->flatMap(fn ($education): array => [
                    $education->institution,
                    $education->degree,
                    $education->field_of_study,
                    $education->description,
                ])->all()),
        ];
    }

    /** @return array<string, string> */
    public function buildTextFromJob(JobPosting $job): array
    {
        $job->loadMissing('skills');

        return [
            'core' => $this->joinParts([
                $job->title,
                $job->department,
                $job->description,
                $job->responsibilities,
                $job->requirements,
            ]),
            'skills' => $job->skills->pluck('name')->filter()->implode(' '),
            'experience' => $this->joinParts([$job->experience_level]),
            'education' => $this->joinParts([$job->education_level]),
        ];
    }

    /**
     * @param  array<array-key, string>  $documents
     * @return array<array-key, array<string, float>>
     */
    public function computeTFIDF(array $documents): array
    {
        $tokenizedDocuments = [];
        $documentFrequencies = [];

        foreach ($documents as $key => $document) {
            $tokens = $this->tokenize($document);
            $tokenizedDocuments[$key] = $tokens;
            foreach (array_keys(array_count_values($tokens)) as $term) {
                $documentFrequencies[$term] = ($documentFrequencies[$term] ?? 0) + 1;
            }
        }

        $documentCount = count($documents);
        $vectors = [];
        foreach ($tokenizedDocuments as $key => $tokens) {
            if ($tokens === []) {
                $vectors[$key] = [];

                continue;
            }

            $termCounts = array_count_values($tokens);
            $vector = [];
            foreach ($termCounts as $term => $count) {
                $tf = $count / count($tokens);
                $idf = log(($documentCount + 1) / (($documentFrequencies[$term] ?? 0) + 1)) + 1;
                $vector[$term] = $tf * $idf;
            }
            $vectors[$key] = $vector;
        }

        return $vectors;
    }

    /**
     * @param  array<string, float>  $vectorA
     * @param  array<string, float>  $vectorB
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        if ($vectorA === [] || $vectorB === []) {
            return 0.0;
        }

        $magnitudeA = $this->vectorMagnitude($vectorA);
        $magnitudeB = $this->vectorMagnitude($vectorB);
        if ($magnitudeA == 0.0 || $magnitudeB == 0.0) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $smallest = count($vectorA) <= count($vectorB) ? $vectorA : $vectorB;
        $largest = $smallest === $vectorA ? $vectorB : $vectorA;
        foreach ($smallest as $term => $value) {
            $dotProduct += $value * ($largest[$term] ?? 0.0);
        }

        return max(0.0, min(1.0, $dotProduct / ($magnitudeA * $magnitudeB)));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function recommendJobsForUser(User $user, int $limit = 10): Collection
    {
        if ($user->role !== UserRole::JOB_SEEKER) {
            throw ValidationException::withMessages(['user' => ['Only job seekers can access recommended jobs.']]);
        }

        $profile = $user->jobSeekerProfile()->with(['skills', 'experiences', 'education'])->first();
        if (! $profile instanceof JobSeekerProfile) {
            throw ValidationException::withMessages([
                'job_seeker_profile' => ['A job seeker profile is required before recommendations can be computed.'],
            ]);
        }

        $jobs = JobPosting::query()
            ->with(['company', 'skills'])
            ->where('status', 'open')
            ->whereHas('company', fn ($query) => $query->where('approval_status', 'approved'))
            ->whereDoesntHave('jobApplications', fn ($query) => $query
                ->where('job_seeker_profile_id', $profile->id))
            ->get();
        if ($jobs->isEmpty()) {
            return collect();
        }

        $documents = ['anchor' => $this->professionalText($this->buildTextFromProfile($profile))];
        foreach ($jobs as $job) {
            $documents[(string) $job->id] = $this->professionalText($this->buildTextFromJob($job));
        }
        $vectors = $this->computeTFIDF($documents);

        return $jobs->map(function (JobPosting $job) use ($profile, $vectors): array {
            $similarity = $this->cosineSimilarity($vectors['anchor'] ?? [], $vectors[(string) $job->id] ?? []);

            return ['job' => $job, ...$this->scoreMatch($job, $profile, $similarity)];
        })->sort(function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: ($this->timestamp($right['job']->published_at) <=> $this->timestamp($left['job']->published_at))
                ?: ($left['job']->id <=> $right['job']->id);
        })->values()->take($limit);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rankCandidatesForJob(JobPosting $jobPosting, int $limit = 10): Collection
    {
        $jobPosting->loadMissing(['company', 'skills']);
        $applications = $jobPosting->jobApplications()->with([
            'applicationStatus',
            'jobSeekerProfile.user',
            'jobSeekerProfile.skills',
            'jobSeekerProfile.experiences',
            'jobSeekerProfile.education',
        ])->get();
        if ($applications->isEmpty()) {
            return collect();
        }

        $documents = ['anchor' => $this->professionalText($this->buildTextFromJob($jobPosting))];
        foreach ($applications as $application) {
            $documents[(string) $application->id] = $this->professionalText(
                $this->buildTextFromProfile($application->jobSeekerProfile),
            );
        }
        $vectors = $this->computeTFIDF($documents);

        return $applications->map(function ($application) use ($jobPosting, $vectors): array {
            $similarity = $this->cosineSimilarity(
                $vectors['anchor'] ?? [],
                $vectors[(string) $application->id] ?? [],
            );

            return [
                'job_application_id' => $application->id,
                'application_status' => $application->applicationStatus,
                'job_seeker_profile' => $application->jobSeekerProfile,
                ...$this->scoreMatch($jobPosting, $application->jobSeekerProfile, $similarity),
            ];
        })->sort(fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: ($left['job_application_id'] <=> $right['job_application_id']))
            ->values()->take($limit);
    }

    /** @return array<string, mixed> */
    public function scoreMatch(JobPosting $job, JobSeekerProfile $profile, float $cosineSimilarity): array
    {
        $weights = $this->componentWeights();
        $job->loadMissing('skills');
        $profile->loadMissing(['skills', 'experiences', 'education']);
        $candidateSkillIds = $profile->skills->pluck('id')->map(fn ($id): int => (int) $id)->flip();

        $required = $this->skillComponent(
            $job->skills->filter(fn ($skill): bool => $this->skillType($skill) === JobSkillRequirementType::REQUIRED),
            $candidateSkillIds,
            $weights['required_skills'],
            false,
        );
        $nice = $this->skillComponent(
            $job->skills->filter(fn ($skill): bool => $this->skillType($skill)?->isNiceToHave() === true),
            $candidateSkillIds,
            $weights['nice_to_have_skills'],
            true,
        );
        $experience = $this->experienceComponent($job, $profile, $weights['experience']);
        $education = $this->educationComponent($job, $profile, $weights['education']);
        $text = $this->textComponent($cosineSimilarity, $weights['text_similarity']);
        $score = $this->roundScore($required['score'] + $nice['score'] + $experience['score'] + $education['score'] + $text['score']);

        $matchedRequired = $required['matched_skills'];
        $missingRequired = $required['missing_skills'];
        $matchedNice = $nice['matched_skills'];
        $breakdown = [
            'required_skills' => $this->withoutSkillLists($required),
            'nice_to_have_skills' => $this->withoutSkillLists($nice),
            'experience' => $experience,
            'education' => $education,
            'text_similarity' => $text,
            // Additive legacy ratios retained for older consumers.
            'skills' => $this->legacySkillRatio($required, $nice),
            'core' => $text['cosine_similarity'],
        ];

        return [
            'score' => max(0.0, min(100.0, $score)),
            'matching_score_version' => (string) config('matching.version', '2.0'),
            'breakdown' => $breakdown,
            'matched_skills' => collect([...$matchedRequired, ...$matchedNice])->pluck('name')->unique()->sort()->values()->all(),
            'skill_breakdown' => [
                'required_skills_matched' => collect($matchedRequired)->pluck('name')->all(),
                'required_skills_missing' => collect($missingRequired)->pluck('name')->all(),
                'optional_skills_matched' => collect($matchedNice)->pluck('name')->all(),
                'nice_to_have_skills_matched' => collect($matchedNice)->pluck('name')->all(),
            ],
            'matched_required_skills' => $matchedRequired,
            'missing_required_skills' => $missingRequired,
            'matched_nice_to_have_skills' => $matchedNice,
            'reasons' => $this->reasons($required, $nice, $experience, $education, $text),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $skills
     * @param  Collection<int, int>  $candidateSkillIds
     * @return array<string, mixed>
     */
    private function skillComponent(Collection $skills, Collection $candidateSkillIds, float $maxScore, bool $notApplicableWhenEmpty): array
    {
        $matched = [];
        $missing = [];
        $matchedWeight = 0;
        $totalWeight = 0;
        foreach ($skills->unique('id') as $skill) {
            $weight = (int) $skill->pivot->weight;
            if ($weight < 1 || $weight > 5) {
                throw new JobPostingOperationException(
                    'The job contains an invalid skill weight.',
                    'JOB_SKILL_WEIGHT_INVALID',
                    422,
                );
            }
            $item = ['id' => (int) $skill->id, 'name' => $skill->name, 'weight' => $weight];
            $totalWeight += $weight;
            if ($candidateSkillIds->has((int) $skill->id)) {
                $matchedWeight += $weight;
                $matched[] = $item;
            } else {
                $missing[] = $item;
            }
        }

        $notApplicable = $notApplicableWhenEmpty && $totalWeight === 0;
        $percentage = $totalWeight === 0 ? ($notApplicable ? 100.0 : 0.0) : $this->roundScore(100 * $matchedWeight / $totalWeight);

        return [
            'score' => $this->roundScore($maxScore * $percentage / 100),
            'max_score' => $maxScore,
            'matched_weight' => $matchedWeight,
            'total_weight' => $totalWeight,
            'match_percentage' => $percentage,
            ...($notApplicableWhenEmpty ? ['not_applicable' => $notApplicable] : []),
            'matched_skills' => $matched,
            'missing_skills' => $missing,
        ];
    }

    /** @return array<string, mixed> */
    private function experienceComponent(JobPosting $job, JobSeekerProfile $profile, float $maxScore): array
    {
        $level = Str::lower(trim((string) $job->experience_level));
        $mapping = config('matching.experience_levels', []);
        if ($level !== '' && ! array_key_exists($level, $mapping)) {
            throw new JobPostingOperationException(
                'The job experience level is not supported by matching configuration.',
                'MATCHING_EXPERIENCE_LEVEL_UNSUPPORTED',
                422,
            );
        }
        $requiredYears = (float) ($mapping[$level] ?? 0);
        $candidateYears = $this->experienceCalculator->years($profile->experiences);
        $percentage = $requiredYears <= 0 ? 100.0 : $this->roundScore(100 * min(1, $candidateYears / $requiredYears));

        return [
            'score' => $this->roundScore($maxScore * $percentage / 100),
            'max_score' => $maxScore,
            'candidate_years' => $candidateYears,
            'required_years' => $requiredYears,
            'match_percentage' => $percentage,
        ];
    }

    /** @return array<string, mixed> */
    private function educationComponent(JobPosting $job, JobSeekerProfile $profile, float $maxScore): array
    {
        $requiredValue = $job->education_level;
        if (blank($requiredValue)) {
            return [
                'score' => $maxScore,
                'max_score' => $maxScore,
                'candidate_level' => $this->educationNormalizer->highest($profile->education)?->value,
                'required_level' => null,
                'not_applicable' => true,
            ];
        }

        $required = EducationLevel::tryFrom((string) $requiredValue);
        if (! $required instanceof EducationLevel) {
            throw new JobPostingOperationException(
                'The job education level is invalid.',
                'MATCHING_EDUCATION_LEVEL_INVALID',
                422,
            );
        }
        $candidate = $this->educationNormalizer->highest($profile->education);
        $score = match (true) {
            $candidate === null => 0.0,
            $candidate->rank() >= $required->rank() => $maxScore,
            $candidate->rank() === $required->rank() - 1 => $maxScore / 2,
            default => 0.0,
        };

        return [
            'score' => $this->roundScore($score),
            'max_score' => $maxScore,
            'candidate_level' => $candidate?->value,
            'required_level' => $required->value,
            'not_applicable' => false,
        ];
    }

    /** @return array<string, float> */
    private function textComponent(float $similarity, float $maxScore): array
    {
        $similarity = max(0.0, min(1.0, $similarity));

        return [
            'score' => $this->roundScore($similarity * $maxScore),
            'max_score' => $maxScore,
            'cosine_similarity' => $this->roundScore($similarity),
            'match_percentage' => $this->roundScore($similarity * 100),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function reasons(array $required, array $nice, array $experience, array $education, array $text): array
    {
        $reasons = [[
            'code' => 'REQUIRED_SKILLS_MATCH',
            'message' => sprintf('Matched %d of %d required skills.', count($required['matched_skills']), count($required['matched_skills']) + count($required['missing_skills'])),
            'value' => $required['match_percentage'],
        ]];
        if ($required['missing_skills'] !== []) {
            $reasons[] = [
                'code' => 'MISSING_REQUIRED_SKILLS',
                'message' => sprintf('Missing %d required skill(s).', count($required['missing_skills'])),
                'skills' => collect($required['missing_skills'])->pluck('name')->all(),
            ];
        }
        $reasons[] = $nice['not_applicable']
            ? ['code' => 'NICE_TO_HAVE_NOT_CONFIGURED', 'message' => 'No nice-to-have skills were configured for this job.', 'value' => 100.0]
            : ['code' => 'NICE_TO_HAVE_SKILLS_MATCH', 'message' => sprintf('Matched %d nice-to-have skill(s).', count($nice['matched_skills'])), 'value' => $nice['match_percentage']];
        $reasons[] = ['code' => 'EXPERIENCE_MATCH', 'message' => sprintf('Candidate has %.2f years against %.2f required.', $experience['candidate_years'], $experience['required_years']), 'value' => $experience['match_percentage']];
        $reasons[] = $education['not_applicable']
            ? ['code' => 'EDUCATION_NOT_CONFIGURED', 'message' => 'No education level was configured for this job.', 'value' => 100.0]
            : ['code' => 'EDUCATION_MATCH', 'message' => 'Education level was compared with the job requirement.', 'value' => $this->ratio($education['score'], $education['max_score']) * 100];
        $reasons[] = ['code' => 'TEXT_SIMILARITY', 'message' => 'Professional profile text was compared with the job text.', 'value' => $text['match_percentage']];

        return $reasons;
    }

    /** @return array<string, float> */
    private function componentWeights(): array
    {
        $weights = config('matching.components', []);
        $requiredKeys = ['required_skills', 'nice_to_have_skills', 'experience', 'education', 'text_similarity'];
        if (array_keys($weights) !== $requiredKeys
            || collect($weights)->contains(fn ($weight): bool => ! is_numeric($weight) || (float) $weight < 0)
            || abs((float) array_sum($weights) - 100.0) > 0.00001) {
            throw new JobPostingOperationException(
                'Matching component weights must be configured and sum to 100.',
                'MATCHING_CONFIGURATION_INVALID',
                500,
            );
        }

        return array_map('floatval', $weights);
    }

    private function skillType($skill): ?JobSkillRequirementType
    {
        $type = $skill->pivot->requirement_type;
        $normalized = JobSkillRequirementType::normalize(
            $type instanceof JobSkillRequirementType ? $type->value : (string) $type,
        );
        if ($normalized === null) {
            throw new JobPostingOperationException(
                'The job contains an invalid skill requirement type.',
                'JOB_SKILL_TYPE_INVALID',
                422,
            );
        }

        return $normalized;
    }

    /** @param array<string, string> $sections */
    private function professionalText(array $sections): string
    {
        return $this->joinParts(array_values($sections));
    }

    /** @param array<int, string|null> $parts */
    private function joinParts(array $parts): string
    {
        return trim(collect($parts)->filter(fn ($part): bool => filled($part))
            ->map(fn ($part): string => trim((string) $part))->implode(' '));
    }

    /** @return array<int, string> */
    private function tokenize(string $document): array
    {
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', Str::lower($document)) ?? '';

        return array_values(array_filter(preg_split('/\s+/u', trim($normalized)) ?: []));
    }

    /** @param array<string, float> $vector */
    private function vectorMagnitude(array $vector): float
    {
        return sqrt(array_reduce($vector, fn (float $carry, float $value): float => $carry + ($value ** 2), 0.0));
    }

    private function roundScore(float $score): float
    {
        return round($score, 2);
    }

    private function ratio(float $value, float $maximum): float
    {
        return $maximum <= 0 ? 0.0 : $this->roundScore($value / $maximum);
    }

    private function legacySkillRatio(array $required, array $nice): float
    {
        $matched = count($required['matched_skills']) + (count($nice['matched_skills']) * 0.5);
        $total = count($required['matched_skills']) + count($required['missing_skills'])
            + ((count($nice['matched_skills']) + count($nice['missing_skills'])) * 0.5);

        return $this->ratio($matched, $total);
    }

    /** @return array<string, mixed> */
    private function withoutSkillLists(array $component): array
    {
        unset($component['matched_skills'], $component['missing_skills']);

        return $component;
    }

    private function timestamp(?Carbon $dateTime): int
    {
        return $dateTime?->getTimestamp() ?? 0;
    }
}
