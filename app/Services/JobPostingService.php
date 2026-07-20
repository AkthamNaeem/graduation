<?php

namespace App\Services;

use App\Enums\JobSkillRequirementType;
use App\Enums\JobWorkMode;
use App\Exceptions\JobPostingOperationException;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobPostingService
{
    /**
     * @var array<int, string>
     */
    private const PUBLIC_SORT_FIELDS = [
        'published_at',
        'created_at',
        'salary_min',
        'salary_max',
        'title',
        'application_deadline',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, JobPosting>
     */
    public function getPublicJobs(array $filters): LengthAwarePaginator
    {
        $query = $this->applyFilters(
            JobPosting::query()
                ->with(['company', 'skills'])
                ->where('status', 'open')
                ->whereHas('company', fn (Builder $query) => $query->where('approval_status', 'approved')),
            $filters,
        );

        $this->applyPublicSorting($query, $filters);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, JobPosting>
     */
    public function getEmployerJobs(User $user, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            $this->employerProfile($user)
                ->company
                ->jobPostings()
                ->with(['company', 'skills'])
                ->latest(),
            $filters,
        )->paginate($this->perPage($filters));
    }

    public function getVisibleJobPosting(JobPosting $jobPosting): JobPosting
    {
        if ($jobPosting->status === 'open' && $jobPosting->company()->where('approval_status', 'approved')->doesntExist()) {
            abort(404);
        }

        return $jobPosting->loadMissing(['company', 'skills']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createJob(User $user, array $data): JobPosting
    {
        return DB::transaction(function () use ($user, $data): JobPosting {
            $skills = $data['skills'] ?? null;
            unset($data['skills']);
            $jobPosting = $this->employerProfile($user)
                ->company
                ->jobPostings()
                ->create([
                    ...$data,
                    'status' => 'draft',
                    'published_at' => null,
                ]);

            if (is_array($skills)) {
                $jobPosting->skills()->sync($this->skillSyncMap($skills));
            }

            $counts = $this->skillCounts($jobPosting);
            $this->auditLogService->record(
                'job.created',
                $user,
                JobPosting::class,
                $jobPosting->id,
                null,
                $jobPosting->only(['title', 'status', 'company_id', 'work_mode', 'application_deadline']),
                [
                    ...$counts,
                    'job_id' => $jobPosting->id,
                    'company_id' => $jobPosting->company_id,
                    'new_work_mode' => $jobPosting->work_mode->value,
                    'new_deadline' => $jobPosting->application_deadline?->toISOString(),
                    'actor_id' => $user->id,
                ],
            );

            return $jobPosting->load(['company', 'skills']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateJob(User $actor, JobPosting $jobPosting, array $data): JobPosting
    {
        return DB::transaction(function () use ($actor, $jobPosting, $data): JobPosting {
            $skillsProvided = array_key_exists('skills', $data);
            $skills = $data['skills'] ?? [];
            unset($data['skills']);
            $safeKeys = array_values(array_intersect(array_keys($data), [
                'title', 'department', 'employment_type', 'experience_level', 'location', 'salary_min', 'salary_max', 'work_mode', 'application_deadline',
            ]));
            $before = $jobPosting->only($safeKeys);
            $previousWorkMode = $jobPosting->work_mode?->value ?? $jobPosting->work_mode;
            $previousDeadline = $jobPosting->application_deadline?->toISOString();

            $jobPosting->update($data);
            if ($skillsProvided) {
                $jobPosting->skills()->sync($this->skillSyncMap($skills));
            }

            $counts = $this->skillCounts($jobPosting);
            $this->auditLogService->record(
                'job.updated',
                $actor,
                JobPosting::class,
                $jobPosting->id,
                $before,
                $jobPosting->only($safeKeys),
                [
                    ...$counts,
                    'job_id' => $jobPosting->id,
                    'company_id' => $jobPosting->company_id,
                    'previous_work_mode' => $previousWorkMode,
                    'new_work_mode' => $jobPosting->work_mode?->value ?? $jobPosting->work_mode,
                    'previous_deadline' => $previousDeadline,
                    'new_deadline' => $jobPosting->application_deadline?->toISOString(),
                    'actor_id' => $actor->id,
                ],
            );

            if (array_key_exists('application_deadline', $data)) {
                $this->auditLogService->record(
                    'job.application_deadline_changed',
                    $actor,
                    JobPosting::class,
                    $jobPosting->id,
                    ['application_deadline' => $previousDeadline],
                    ['application_deadline' => $jobPosting->application_deadline?->toISOString()],
                    ['job_id' => $jobPosting->id, 'company_id' => $jobPosting->company_id, 'actor_id' => $actor->id],
                );
            }

            return $jobPosting->load(['company', 'skills']);
        });
    }

    public function deleteJob(JobPosting $jobPosting): void
    {
        $jobPosting->delete();
    }

    public function publishJob(User $actor, JobPosting $jobPosting): JobPosting
    {
        if (blank($jobPosting->title) || blank($jobPosting->description) || blank($jobPosting->employment_type)) {
            throw ValidationException::withMessages([
                'job' => ['Title, description, and employment type are required before publishing this job.'],
            ]);
        }

        if (blank($jobPosting->requirements)) {
            throw new JobPostingOperationException(
                'Job requirements are required before publishing this job.',
                'JOB_REQUIREMENTS_MISSING',
                422,
                ['requirements' => ['Job requirements are required before publishing this job.']],
            );
        }

        $workMode = JobWorkMode::tryFrom((string) $jobPosting->getRawOriginal('work_mode'));
        if (! $workMode instanceof JobWorkMode
            || ($workMode->requiresLocation() && blank($jobPosting->location))) {
            throw new JobPostingOperationException(
                'A valid work mode and location for on-site or hybrid jobs are required before publishing.',
                'INVALID_JOB_WORK_MODE',
                422,
                ['work_mode' => ['A valid work mode and location for on-site or hybrid jobs are required before publishing.']],
            );
        }

        if ($jobPosting->requiredSkillsCount() < 1) {
            throw new JobPostingOperationException(
                'At least one required skill is needed before publishing this job.',
                'JOB_REQUIRED_SKILL_MISSING',
                422,
                ['skills' => ['At least one required skill is needed before publishing this job.']],
            );
        }

        if ($jobPosting->isApplicationDeadlinePassed()) {
            throw new JobPostingOperationException(
                'The application deadline must be in the future before publishing this job.',
                'JOB_APPLICATION_DEADLINE_PASSED',
                422,
                ['application_deadline' => ['The application deadline must be in the future before publishing this job.']],
            );
        }

        $before = $jobPosting->only(['status', 'published_at']);

        $jobPosting->forceFill([
            'status' => 'open',
            'published_at' => now(),
        ])->save();

        $this->auditLogService->record(
            'job.published',
            $actor,
            JobPosting::class,
            $jobPosting->id,
            $before,
            $jobPosting->only(['status', 'published_at']),
        );

        return $jobPosting->load(['company', 'skills']);
    }

    public function closeJob(User $actor, JobPosting $jobPosting): JobPosting
    {
        $before = $jobPosting->only(['status']);

        $jobPosting->forceFill([
            'status' => 'closed',
        ])->save();

        $this->auditLogService->record(
            'job.closed',
            $actor,
            JobPosting::class,
            $jobPosting->id,
            $before,
            $jobPosting->only(['status']),
        );

        return $jobPosting->load(['company', 'skills']);
    }

    /**
     * @param  array<int, int>  $skillIds
     */
    public function attachSkills(User $actor, JobPosting $jobPosting, array $data): JobPosting
    {
        $items = isset($data['skills'])
            ? $data['skills']
            : array_map(fn (int $skillId): array => [
                'skill_id' => $skillId,
                'requirement_type' => JobSkillRequirementType::REQUIRED->value,
            ], $data['skill_ids'] ?? []);
        $jobPosting->skills()->syncWithoutDetaching($this->skillSyncMap($items));
        $counts = $this->skillCounts($jobPosting);
        $this->auditLogService->record(
            'job.skills_updated',
            $actor,
            JobPosting::class,
            $jobPosting->id,
            null,
            null,
            [...$counts, 'job_id' => $jobPosting->id, 'company_id' => $jobPosting->company_id, 'actor_id' => $actor->id],
        );

        return $jobPosting->load(['company', 'skills']);
    }

    public function detachSkills(User $actor, JobPosting $jobPosting, Skill $skill): JobPosting
    {
        $jobPosting->skills()->detach($skill->id);
        $counts = $this->skillCounts($jobPosting);
        $this->auditLogService->record(
            'job.skills_updated',
            $actor,
            JobPosting::class,
            $jobPosting->id,
            null,
            null,
            [...$counts, 'job_id' => $jobPosting->id, 'company_id' => $jobPosting->company_id, 'actor_id' => $actor->id],
        );

        return $jobPosting->load(['company', 'skills']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder|Relation $query, array $filters): Builder|Relation
    {
        $search = $filters['search'] ?? null;
        $location = $filters['location'] ?? null;
        $skill = $filters['skill'] ?? null;
        $experienceLevel = $filters['experience_level'] ?? null;
        $employmentType = $filters['employment_type'] ?? null;
        $workMode = $filters['work_mode'] ?? null;
        $acceptingApplications = $filters['accepting_applications'] ?? null;
        $skillRequirement = $filters['skill_requirement'] ?? null;
        $salaryMin = $filters['salary_min'] ?? null;
        $salaryMax = $filters['salary_max'] ?? null;

        if (filled($search)) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (filled($location)) {
            $query->where('location', 'like', '%'.$location.'%');
        }

        if (filled($experienceLevel)) {
            $query->where('experience_level', $experienceLevel);
        }

        if (filled($employmentType)) {
            $query->where('employment_type', $employmentType);
        }

        if (filled($workMode)) {
            $query->where('work_mode', $workMode);
        }

        if ($acceptingApplications !== null) {
            $accepting = filter_var($acceptingApplications, FILTER_VALIDATE_BOOLEAN);
            $query->where(function (Builder $builder) use ($accepting): void {
                if ($accepting) {
                    $builder->where('status', 'open')
                        ->where(function (Builder $deadlineQuery): void {
                            $deadlineQuery->whereNull('application_deadline')
                                ->orWhere('application_deadline', '>=', now());
                        });

                    return;
                }

                $builder->where('status', '!=', 'open')
                    ->orWhere(function (Builder $deadlineQuery): void {
                        $deadlineQuery->whereNotNull('application_deadline')
                            ->where('application_deadline', '<', now());
                    });
            });
        }

        if (filled($salaryMin)) {
            $query->where(function (Builder $builder) use ($salaryMin): void {
                $builder->whereNull('salary_max')
                    ->orWhere('salary_max', '>=', $salaryMin);
            });
        }

        if (filled($salaryMax)) {
            $query->where(function (Builder $builder) use ($salaryMax): void {
                $builder->whereNull('salary_min')
                    ->orWhere('salary_min', '<=', $salaryMax);
            });
        }

        if (filled($skill)) {
            $query->whereHas('skills', function (Builder $builder) use ($skill, $skillRequirement): void {
                if (is_numeric($skill)) {
                    $builder->where('skills.id', (int) $skill);

                    if (filled($skillRequirement)) {
                        $builder->where('job_posting_skills.requirement_type', $skillRequirement);
                    }

                    return;
                }

                $builder->where('skills.slug', $skill);
                if (filled($skillRequirement)) {
                    $builder->where('job_posting_skills.requirement_type', $skillRequirement);
                }
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyPublicSorting(Builder|Relation $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'published_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        if (! in_array($sortBy, self::PUBLIC_SORT_FIELDS, true)) {
            $sortBy = 'published_at';
        }

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        if ($sortBy === 'application_deadline') {
            $query->orderByRaw('CASE WHEN application_deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('application_deadline', $sortDirection);

            return;
        }

        $query->orderBy($sortBy, $sortDirection);
    }

    private function employerProfile(User $user): EmployerProfile
    {
        return $user->employerProfile()->with('company')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return (int) ($filters['per_page'] ?? 15);
    }

    /** @param array<int, array{skill_id: int, requirement_type: string}> $skills */
    private function skillSyncMap(array $skills): array
    {
        $map = [];
        foreach ($skills as $skill) {
            $map[(int) $skill['skill_id']] = ['requirement_type' => $skill['requirement_type']];
        }

        return $map;
    }

    /** @return array{required_skill_count: int, optional_skill_count: int} */
    private function skillCounts(JobPosting $jobPosting): array
    {
        $counts = DB::table('job_posting_skills')
            ->where('job_posting_id', $jobPosting->id)
            ->selectRaw('requirement_type, COUNT(*) as aggregate')
            ->groupBy('requirement_type')
            ->pluck('aggregate', 'requirement_type');

        return [
            'required_skill_count' => (int) ($counts[JobSkillRequirementType::REQUIRED->value] ?? 0),
            'optional_skill_count' => (int) ($counts[JobSkillRequirementType::OPTIONAL->value] ?? 0),
        ];
    }
}
