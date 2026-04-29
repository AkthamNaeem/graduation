<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MatchingService
{
    /**
     * @var array<string, float>
     */
    private const SECTION_WEIGHTS = [
        'skills' => 0.50,
        'experience' => 0.25,
        'core' => 0.15,
        'education' => 0.10,
    ];

    /**
     * @return array<string, string>
     */
    public function buildTextFromProfile(JobSeekerProfile $profile): array
    {
        $profile->loadMissing(['skills', 'experiences', 'education']);

        return [
            'core' => $this->joinParts([
                $profile->headline,
                $profile->summary,
                $profile->location,
            ]),
            'skills' => $profile->skills
                ->pluck('name')
                ->filter()
                ->implode(' '),
            'experience' => $this->joinParts(
                $profile->experiences
                    ->flatMap(fn ($experience): array => [
                        $experience->title,
                        $experience->company_name,
                        $experience->location,
                        $experience->description,
                    ])
                    ->all(),
            ),
            'education' => $this->joinParts(
                $profile->education
                    ->flatMap(fn ($education): array => [
                        $education->institution,
                        $education->degree,
                        $education->field_of_study,
                        $education->description,
                    ])
                    ->all(),
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function buildTextFromJob(JobPosting $job): array
    {
        $job->loadMissing(['skills']);

        return [
            'core' => $this->joinParts([
                $job->title,
                $job->description,
                $job->employment_type,
                $job->experience_level,
                $job->location,
            ]),
            'skills' => $job->skills
                ->pluck('name')
                ->filter()
                ->implode(' '),
            'experience' => $this->joinParts([
                $job->experience_level,
                $job->title,
                $job->description,
            ]),
            'education' => '',
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
            $totalTerms = count($tokens);
            $vector = [];

            foreach ($termCounts as $term => $count) {
                $tf = $count / $totalTerms;
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
        $smallestVector = count($vectorA) <= count($vectorB) ? $vectorA : $vectorB;
        $largestVector = $smallestVector === $vectorA ? $vectorB : $vectorA;

        foreach ($smallestVector as $term => $value) {
            $dotProduct += $value * ($largestVector[$term] ?? 0.0);
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function recommendJobsForUser(User $user, int $limit = 10): Collection
    {
        if ($user->role !== UserRole::JOB_SEEKER) {
            throw ValidationException::withMessages([
                'user' => ['Only job seekers can access recommended jobs.'],
            ]);
        }

        $profile = $user->jobSeekerProfile()
            ->with(['skills', 'experiences', 'education'])
            ->first();

        if (! $profile instanceof JobSeekerProfile) {
            throw ValidationException::withMessages([
                'job_seeker_profile' => ['A job seeker profile is required before recommendations can be computed.'],
            ]);
        }

        $jobs = JobPosting::query()
            ->with(['company', 'skills'])
            ->where('status', 'open')
            ->whereDoesntHave('jobApplications', function ($query) use ($profile): void {
                $query->where('job_seeker_profile_id', $profile->id);
            })
            ->get();

        if ($jobs->isEmpty()) {
            return collect();
        }

        $jobSections = $jobs->mapWithKeys(fn (JobPosting $job): array => [
            (string) $job->id => $this->buildTextFromJob($job),
        ])->all();
        $scoreCards = $this->scoreCandidates($this->buildTextFromProfile($profile), $jobSections);

        return $jobs
            ->map(function (JobPosting $job) use ($profile, $scoreCards): array {
                $scoreCard = $scoreCards[(string) $job->id] ?? $this->emptyScoreCard();

                return [
                    'job' => $job,
                    'score' => $scoreCard['score'],
                    'breakdown' => $scoreCard['breakdown'],
                    'matched_skills' => $this->matchedSkills($profile->skills->pluck('name')->all(), $job->skills->pluck('name')->all()),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $scoreOrder = $right['score'] <=> $left['score'];

                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }

                $leftPublishedAt = $this->timestamp($left['job']->published_at);
                $rightPublishedAt = $this->timestamp($right['job']->published_at);
                $publishedAtOrder = $rightPublishedAt <=> $leftPublishedAt;

                if ($publishedAtOrder !== 0) {
                    return $publishedAtOrder;
                }

                return $left['job']->id <=> $right['job']->id;
            })
            ->values()
            ->take($limit);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rankCandidatesForJob(JobPosting $jobPosting, int $limit = 10): Collection
    {
        $jobPosting->loadMissing(['company', 'skills']);

        $applications = $jobPosting->jobApplications()
            ->with([
                'applicationStatus',
                'jobSeekerProfile.user',
                'jobSeekerProfile.skills',
                'jobSeekerProfile.experiences',
                'jobSeekerProfile.education',
            ])
            ->get();

        if ($applications->isEmpty()) {
            return collect();
        }

        $candidateSections = $applications->mapWithKeys(fn ($application): array => [
            (string) $application->id => $this->buildTextFromProfile($application->jobSeekerProfile),
        ])->all();
        $scoreCards = $this->scoreCandidates($this->buildTextFromJob($jobPosting), $candidateSections);
        $jobSkillNames = $jobPosting->skills->pluck('name')->all();

        return $applications
            ->map(function ($application) use ($jobSkillNames, $scoreCards): array {
                $scoreCard = $scoreCards[(string) $application->id] ?? $this->emptyScoreCard();

                return [
                    'job_application_id' => $application->id,
                    'application_status' => $application->applicationStatus,
                    'job_seeker_profile' => $application->jobSeekerProfile,
                    'score' => $scoreCard['score'],
                    'breakdown' => $scoreCard['breakdown'],
                    'matched_skills' => $this->matchedSkills(
                        $application->jobSeekerProfile->skills->pluck('name')->all(),
                        $jobSkillNames,
                    ),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $scoreOrder = $right['score'] <=> $left['score'];

                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }

                return $left['job_application_id'] <=> $right['job_application_id'];
            })
            ->values()
            ->take($limit);
    }

    /**
     * @param  array<string, string>  $anchorSections
     * @param  array<string, array<string, string>>  $candidateSections
     * @return array<string, array{score: float, breakdown: array<string, float>}>
     */
    private function scoreCandidates(array $anchorSections, array $candidateSections): array
    {
        $scoreCards = [];

        foreach ($candidateSections as $candidateKey => $sections) {
            $scoreCards[$candidateKey] = $this->emptyScoreCard();
        }

        foreach (self::SECTION_WEIGHTS as $section => $weight) {
            $documents = ['anchor' => $anchorSections[$section] ?? ''];

            foreach ($candidateSections as $candidateKey => $sections) {
                $documents[$candidateKey] = $sections[$section] ?? '';
            }

            $vectors = $this->computeTFIDF($documents);
            $anchorVector = $vectors['anchor'] ?? [];

            foreach (array_keys($candidateSections) as $candidateKey) {
                $sectionScore = $this->cosineSimilarity($anchorVector, $vectors[$candidateKey] ?? []);
                $scoreCards[$candidateKey]['breakdown'][$section] = $this->roundScore($sectionScore);
                $scoreCards[$candidateKey]['score'] += $sectionScore * $weight;
            }
        }

        foreach (array_keys($scoreCards) as $candidateKey) {
            $scoreCards[$candidateKey]['score'] = $this->roundScore($scoreCards[$candidateKey]['score']);
        }

        return $scoreCards;
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function joinParts(array $parts): string
    {
        return trim(collect($parts)
            ->filter(fn ($part): bool => filled($part))
            ->map(fn ($part): string => trim((string) $part))
            ->implode(' '));
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $document): array
    {
        $normalized = Str::lower($document);
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', $normalized) ?? '';

        return array_values(array_filter(
            preg_split('/\s+/u', trim($normalized)) ?: [],
            fn ($token): bool => $token !== '',
        ));
    }

    /**
     * @param  array<string, float>  $vector
     */
    private function vectorMagnitude(array $vector): float
    {
        return sqrt(array_reduce(
            $vector,
            fn (float $carry, float $value): float => $carry + ($value ** 2),
            0.0,
        ));
    }

    /**
     * @param  array<int, string>  $leftSkills
     * @param  array<int, string>  $rightSkills
     * @return array<int, string>
     */
    private function matchedSkills(array $leftSkills, array $rightSkills): array
    {
        $leftLookup = [];

        foreach ($leftSkills as $skillName) {
            $normalized = Str::lower(trim($skillName));

            if ($normalized !== '') {
                $leftLookup[$normalized] = $skillName;
            }
        }

        $matchedSkills = [];

        foreach ($rightSkills as $skillName) {
            $normalized = Str::lower(trim($skillName));

            if ($normalized !== '' && isset($leftLookup[$normalized])) {
                $matchedSkills[] = $leftLookup[$normalized];
            }
        }

        $matchedSkills = array_values(array_unique($matchedSkills));
        sort($matchedSkills, SORT_NATURAL | SORT_FLAG_CASE);

        return $matchedSkills;
    }

    /**
     * @return array{score: float, breakdown: array<string, float>}
     */
    private function emptyScoreCard(): array
    {
        return [
            'score' => 0.0,
            'breakdown' => [
                'skills' => 0.0,
                'experience' => 0.0,
                'core' => 0.0,
                'education' => 0.0,
            ],
        ];
    }

    private function roundScore(float $score): float
    {
        return round($score, 6);
    }

    private function timestamp(?Carbon $dateTime): int
    {
        return $dateTime?->getTimestamp() ?? 0;
    }
}
