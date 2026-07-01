<?php

namespace App\Services;

use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class JobPostingService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, JobPosting>
     */
    public function getPublicJobs(array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(
            JobPosting::query()
                ->with(['company', 'skills'])
                ->where('status', 'open')
                ->orderByDesc('published_at'),
            $filters,
        )->paginate($this->perPage($filters));
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
        return $jobPosting->loadMissing(['company', 'skills']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createJob(User $user, array $data): JobPosting
    {
        $jobPosting = $this->employerProfile($user)
            ->company
            ->jobPostings()
            ->create([
                ...$data,
                'status' => 'draft',
                'published_at' => null,
            ]);

        $this->auditLogService->record(
            'job.created',
            $user,
            JobPosting::class,
            $jobPosting->id,
            null,
            $jobPosting->only(['title', 'status', 'company_id']),
        );

        return $jobPosting->load(['company', 'skills']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateJob(User $actor, JobPosting $jobPosting, array $data): JobPosting
    {
        $before = $jobPosting->only(array_keys($data));

        $jobPosting->update($data);

        $this->auditLogService->record(
            'job.updated',
            $actor,
            JobPosting::class,
            $jobPosting->id,
            $before,
            $jobPosting->only(array_keys($data)),
        );

        return $jobPosting->load(['company', 'skills']);
    }

    public function deleteJob(JobPosting $jobPosting): void
    {
        $jobPosting->delete();
    }

    public function publishJob(User $actor, JobPosting $jobPosting): JobPosting
    {
        if (! $jobPosting->skills()->exists()) {
            throw ValidationException::withMessages([
                'skills' => ['A job posting must have at least one skill before it can be published.'],
            ]);
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
    public function attachSkills(JobPosting $jobPosting, array $skillIds): JobPosting
    {
        $jobPosting->skills()->syncWithoutDetaching($skillIds);

        return $jobPosting->load(['company', 'skills']);
    }

    public function detachSkills(JobPosting $jobPosting, Skill $skill): JobPosting
    {
        $jobPosting->skills()->detach($skill->id);

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

        if (filled($skill)) {
            $query->whereHas('skills', function (Builder $builder) use ($skill): void {
                if (is_numeric($skill)) {
                    $builder->where('skills.id', (int) $skill);

                    return;
                }

                $builder->where('skills.slug', $skill);
            });
        }

        return $query;
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
}
