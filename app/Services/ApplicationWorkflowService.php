<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\ApplicationStatusChanged;
use App\Events\ApplicationSubmitted;
use App\Models\ApplicationStatus;
use App\Models\ApplicationStatusHistory;
use App\Models\CVFile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
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
        'under_review' => ['shortlisted', 'test_pending', 'interview_pending', 'interview_scheduled', 'final_review', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'shortlisted' => ['test_pending', 'interview_pending', 'interview_scheduled', 'final_review', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'test_pending' => ['test_completed', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'test_completed' => ['interview_pending', 'interview_scheduled', 'final_review', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'interview_pending' => ['interview_scheduled', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'interview_scheduled' => ['interview_pending', 'interview_completed', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'interview_completed' => ['interview_scheduled', 'final_review', 'accepted', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'final_review' => ['accepted', 'rejected', 'on_hold', 'need_more_information', 'withdrawn'],
        'need_more_information' => ['under_review', 'shortlisted', 'test_pending', 'interview_pending', 'interview_scheduled', 'final_review', 'rejected', 'on_hold', 'withdrawn'],
        'on_hold' => ['under_review', 'shortlisted', 'test_pending', 'interview_pending', 'interview_scheduled', 'final_review', 'rejected', 'withdrawn'],
        'accepted' => [],
        'rejected' => [],
        'withdrawn' => [],
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $applicationData
     */
    public function applyToJob(User $user, JobPosting $jobPosting, array $applicationData): JobApplication
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

        $selectedCvFileId = (int) ($applicationData['selected_cv_file_id'] ?? 0);
        $this->ensureSelectedCvBelongsToUser($selectedCvFileId, $user);

        return DB::transaction(function () use ($jobPosting, $profile, $user, $applicationData, $selectedCvFileId): JobApplication {
            $this->checkDuplicateApplication($jobPosting, $profile);

            $submittedStatus = $this->statusBySlug(self::STATUS_SUBMITTED);

            $application = JobApplication::create([
                'job_posting_id' => $jobPosting->id,
                'job_seeker_profile_id' => $profile->id,
                'selected_cv_file_id' => $selectedCvFileId,
                'application_status_id' => $submittedStatus->id,
                'cover_letter' => $applicationData['cover_letter'] ?? null,
                'consent_to_share_profile' => (bool) ($applicationData['consent_to_share_profile'] ?? false),
                'screening_answers' => $applicationData['screening_answers'] ?? null,
            ]);

            $this->recordHistory($application, null, $submittedStatus, $user);

            DB::afterCommit(fn (): array => event(new ApplicationSubmitted($application->id)));

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

            if (in_array($toStatus->slug, ['accepted', 'rejected'], true)) {
                $this->auditLogService->record(
                    $toStatus->slug === 'accepted' ? 'application.accepted' : 'application.rejected',
                    $user,
                    JobApplication::class,
                    $application->id,
                    ['status' => $fromStatus->slug],
                    ['status' => $toStatus->slug],
                    ['note' => $note],
                );
            }

            DB::afterCommit(fn (): array => event(new ApplicationStatusChanged(
                $application->id,
                $fromStatus->slug,
                $toStatus->slug,
                $user->id,
                $note,
            )));

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

            DB::afterCommit(fn (): array => event(new ApplicationStatusChanged(
                $application->id,
                $fromStatus->slug,
                $withdrawnStatus->slug,
                $user->id,
                $note,
            )));

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
     * @return LengthAwarePaginator<int, JobApplication>
     */
    public function getMyApplications(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $profileId = $user->jobSeekerProfile?->id;

        return JobApplication::query()
            ->with($this->applicationRelations())
            ->where('job_seeker_profile_id', $profileId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, JobApplication>
     */
    public function getJobApplications(JobPosting $jobPosting, int $perPage = 15): LengthAwarePaginator
    {
        return $jobPosting->jobApplications()
            ->with($this->applicationRelations())
            ->latest()
            ->paginate($perPage);
    }

    public function getApplication(JobApplication $jobApplication): JobApplication
    {
        return $this->loadApplication($jobApplication);
    }

    private function ensureSelectedCvBelongsToUser(int $selectedCvFileId, User $user): void
    {
        $exists = CVFile::query()
            ->whereKey($selectedCvFileId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'selected_cv_file_id' => ['The selected CV file must belong to the authenticated job seeker.'],
            ]);
        }
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
            'selectedCvFile',
            'applicationStatus',
            'statusHistory.fromStatus',
            'statusHistory.toStatus',
            'statusHistory.changedBy',
        ];
    }
}
