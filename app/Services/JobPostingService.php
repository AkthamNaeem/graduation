<?php

namespace App\Services;

use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

class JobPostingService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, JobPosting>
     */
    public function getPublicJobs(array $filters): Collection
    {
        return $this->applyFilters(
            JobPosting::query()
                ->with(['company', 'skills'])
                ->where('status', 'open')
                ->orderByDesc('published_at'),
            $filters,
        )->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, JobPosting>
     */
    public function getEmployerJobs(User $user, array $filters): Collection
    {
        return $this->applyFilters(
            $this->employerProfile($user)
                ->company
                ->jobPostings()
                ->with(['company', 'skills'])
                ->latest(),
            $filters,
        )->get();
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

        return $jobPosting->load(['company', 'skills']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateJob(JobPosting $jobPosting, array $data): JobPosting
    {
        $jobPosting->update($data);

        return $jobPosting->load(['company', 'skills']);
    }

    public function deleteJob(JobPosting $jobPosting): void
    {
        $jobPosting->delete();
    }

    public function publishJob(JobPosting $jobPosting): JobPosting
    {
        if (! $jobPosting->skills()->exists()) {
            throw ValidationException::withMessages([
                'skills' => ['A job posting must have at least one skill before it can be published.'],
            ]);
        }

        $jobPosting->forceFill([
            'status' => 'open',
            'published_at' => now(),
        ])->save();

        return $jobPosting->load(['company', 'skills']);
    }

    public function closeJob(JobPosting $jobPosting): JobPosting
    {
        $jobPosting->forceFill([
            'status' => 'closed',
        ])->save();

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
}
