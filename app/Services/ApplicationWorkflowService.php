<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\ApplicationStatusHistory;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationWorkflowService
{
    private const STATUS_SUBMITTED = 'submitted';

    private const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @var array<int, string>
     */
    private const TERMINAL_STATUSES = [
        'accepted',
        'rejected',
        'withdrawn',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const VALID_TRANSITIONS = [
        'submitted' => ['under_review', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'under_review' => ['shortlisted', 'test_pending', 'interview_pending', 'final_review', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'shortlisted' => ['test_pending', 'interview_pending', 'final_review', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'test_pending' => ['test_completed', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'test_completed' => ['interview_pending', 'final_review', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'interview_pending' => ['interview_scheduled', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'interview_scheduled' => ['interview_completed', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'interview_completed' => ['final_review', 'accepted', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'final_review' => ['accepted', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'need_more_information' => ['under_review', 'shortlisted', 'test_pending', 'interview_pending', 'final_review', 'rejected', 'on_hold', 'withdrawn'],
        'on_hold' => ['under_review', 'shortlisted', 'test_pending', 'interview_pending', 'final_review', 'rejected', 'withdrawn'],
        'accepted' => [],
        'rejected' => [],
        'withdrawn' => [],
    ];

    public function applyToJob(User $user, JobPosting $jobPosting): JobApplication
    {
        if ($user->role !== UserRole::JOB_SEEKER) {
            throw ValidationException::withMessages([
                'user' => ['Only job seekers can apply to jobs.'],
            ]);
        }

        if ($jobPosting->status !== 'open') {
            throw ValidationException::withMessages([
                'job_posting_id' => ['Applications are only allowed for open jobs.'],
            ]);
        }

        $profile = $user->jobSeekerProfile;

        if (! $profile instanceof JobSeekerProfile) {
            throw ValidationException::withMessages([
                'job_seeker_profile' => ['A job seeker profile is required before applying.'],
            ]);
        }

        $this->checkDuplicateApplication($jobPosting, $profile);

        return DB::transaction(function () use ($jobPosting, $profile, $user): JobApplication {
            $submittedStatus = $this->statusBySlug(self::STATUS_SUBMITTED);

            $application = JobApplication::create([
                'job_posting_id' => $jobPosting->id,
                'job_seeker_profile_id' => $profile->id,
                'application_status_id' => $submittedStatus->id,
            ]);

            $this->recordHistory($application, null, $submittedStatus, $user);

            return $this->loadApplication($application);
        });
    }

    public function changeStatus(User $user, JobApplication $jobApplication, string $targetStatusSlug, ?string $note = null): JobApplication
    {
        if ($targetStatusSlug === self::STATUS_WITHDRAWN) {
            throw ValidationException::withMessages([
                'status' => ['Employers cannot move an application to withdrawn.'],
            ]);
        }

        return DB::transaction(function () use ($jobApplication, $targetStatusSlug, $user, $note): JobApplication {
            $application = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($jobApplication->id);

            $fromStatus = $application->applicationStatus;
            $this->validateTransition($fromStatus->slug, $targetStatusSlug);

            $toStatus = $this->statusBySlug($targetStatusSlug);

            $application->forceFill([
                'application_status_id' => $toStatus->id,
            ])->save();

            $this->recordHistory($application, $fromStatus, $toStatus, $user, $note);

            return $this->loadApplication($application);
        });
    }

    public function withdrawApplication(User $user, JobApplication $jobApplication, ?string $note = null): JobApplication
    {
        return DB::transaction(function () use ($jobApplication, $user, $note): JobApplication {
            $application = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($jobApplication->id);

            $fromStatus = $application->applicationStatus;
            $this->validateTransition($fromStatus->slug, self::STATUS_WITHDRAWN);
            $withdrawnStatus = $this->statusBySlug(self::STATUS_WITHDRAWN);

            $application->forceFill([
                'application_status_id' => $withdrawnStatus->id,
            ])->save();

            $this->recordHistory($application, $fromStatus, $withdrawnStatus, $user, $note);

            return $this->loadApplication($application);
        });
    }

    public function validateTransition(string $currentSlug, string $targetSlug): void
    {
        if (in_array($currentSlug, self::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Terminal application states cannot be changed.'],
            ]);
        }

        $allowedTransitions = self::VALID_TRANSITIONS[$currentSlug] ?? [];

        if (! in_array($targetSlug, $allowedTransitions, true)) {
            throw ValidationException::withMessages([
                'status' => ["The transition from {$currentSlug} to {$targetSlug} is not allowed."],
            ]);
        }
    }

    public function recordHistory(
        JobApplication $jobApplication,
        ?ApplicationStatus $from,
        ApplicationStatus $to,
        User $actor,
        ?string $note = null,
    ): void {
        ApplicationStatusHistory::create([
            'job_application_id' => $jobApplication->id,
            'from_application_status_id' => $from?->id,
            'to_application_status_id' => $to->id,
            'changed_by_user_id' => $actor->id,
            'note' => $note,
        ]);
    }

    public function checkDuplicateApplication(JobPosting $jobPosting, JobSeekerProfile $profile): void
    {
        $exists = JobApplication::query()
            ->where('job_posting_id', $jobPosting->id)
            ->where('job_seeker_profile_id', $profile->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'job_posting_id' => ['You have already applied to this job.'],
            ]);
        }
    }

    /**
     * @return Collection<int, JobApplication>
     */
    public function getMyApplications(User $user): Collection
    {
        $profileId = $user->jobSeekerProfile?->id;

        return JobApplication::query()
            ->with($this->applicationRelations())
            ->where('job_seeker_profile_id', $profileId)
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, JobApplication>
     */
    public function getJobApplications(JobPosting $jobPosting): Collection
    {
        return $jobPosting->jobApplications()
            ->with($this->applicationRelations())
            ->latest()
            ->get();
    }

    public function getApplication(JobApplication $jobApplication): JobApplication
    {
        return $this->loadApplication($jobApplication);
    }

    private function statusBySlug(string $slug): ApplicationStatus
    {
        return ApplicationStatus::query()
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function loadApplication(JobApplication $jobApplication): JobApplication
    {
        return $jobApplication->load($this->applicationRelations());
    }

    /**
     * @return array<int, string>
     */
    private function applicationRelations(): array
    {
        return [
            'jobPosting.company',
            'jobPosting.skills',
            'jobSeekerProfile.user',
            'jobSeekerProfile.skills',
            'applicationStatus',
            'statusHistory.fromStatus',
            'statusHistory.toStatus',
            'statusHistory.changedBy',
        ];
    }
}
