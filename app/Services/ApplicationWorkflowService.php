<?php

namespace App\Services;

use App\Enums\ApplicationInformationRequestStatus;
use App\Enums\UserRole;
use App\Events\ApplicationStatusChanged;
use App\Events\ApplicationSubmitted;
use App\Exceptions\ApplicationInformationRequestException;
use App\Exceptions\CVLifecycleException;
use App\Exceptions\JobPostingOperationException;
use App\Models\ApplicationInformationRequest;
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
use Throwable;

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
        private readonly ApplicationScreeningAnswerService $screeningAnswerService,
        private readonly CompanyRecruitmentAccessService $companyAccessService,
        private readonly PrivateFileStorageService $privateStorage,
        private readonly ApplicationPageService $applicationPageService,
        private readonly ApplicationSnapshotService $applicationSnapshotService,
    ) {}

    /**
     * @param  array<string, mixed>  $applicationData
     */
    public function applyToJob(User $user, JobPosting $jobPosting, array $applicationData): JobApplication
    {
        if ($user->role !== UserRole::JOB_SEEKER) {
            throw ValidationException::withMessages([
                'user' => [__('jobs.job_seeker_only')],
            ]);
        }

        if (($applicationData['consent_to_share_profile'] ?? null) !== true) {
            throw ValidationException::withMessages([
                'consent_to_share_profile' => ['Explicit profile sharing consent is required.'],
            ]);
        }

        $this->assertJobAcceptsApplications($jobPosting);

        $profile = $user->jobSeekerProfile;

        if (! $profile instanceof JobSeekerProfile) {
            throw ValidationException::withMessages([
                'job_seeker_profile' => [__('jobs.profile_required')],
            ]);
        }

        $createdSnapshot = null;
        $databaseCommitted = false;

        try {
            return DB::transaction(function () use ($jobPosting, $profile, $user, $applicationData, &$createdSnapshot, &$databaseCommitted): JobApplication {
                $lockedProfile = JobSeekerProfile::query()->lockForUpdate()->findOrFail($profile->id);
                $selectedCvFile = $this->resolveApplicationCV($user, $lockedProfile, $applicationData['selected_cv_file_id'] ?? null);
                $lockedJobPosting = JobPosting::query()->lockForUpdate()->findOrFail($jobPosting->id);
                $this->assertJobAcceptsApplications($lockedJobPosting);
                $this->checkDuplicateApplication($lockedJobPosting, $profile);
                $screeningPlan = $this->screeningAnswerService->buildPlan(
                    $lockedJobPosting,
                    $applicationData['screening_answers'] ?? [],
                );

                $submittedStatus = $this->statusBySlug(self::STATUS_SUBMITTED);

                $application = JobApplication::create([
                    'job_posting_id' => $lockedJobPosting->id,
                    'job_seeker_profile_id' => $profile->id,
                    'selected_cv_file_id' => $selectedCvFile->id,
                    'application_status_id' => $submittedStatus->id,
                    'cover_letter' => $applicationData['cover_letter'] ?? null,
                    'consent_to_share_profile' => true,
                    'screening_answers' => null,
                ]);

                $createdSnapshot = $this->applicationSnapshotService->capture(
                    $application,
                    $lockedProfile,
                    $selectedCvFile,
                    $screeningPlan,
                );
                $this->screeningAnswerService->persistSnapshots($application, $screeningPlan);
                $this->recordHistory($application, null, $submittedStatus, $user);

                $this->auditLogService->record(
                    'application.submitted',
                    $user,
                    JobApplication::class,
                    $application->id,
                    null,
                    ['status' => self::STATUS_SUBMITTED],
                    [
                        'job_posting_id' => $application->job_posting_id,
                        'snapshot_id' => $createdSnapshot->id,
                        'schema_version' => $createdSnapshot->schema_version,
                    ],
                );

                DB::afterCommit(function () use (&$databaseCommitted): void {
                    $databaseCommitted = true;
                });
                DB::afterCommit(fn (): array => event(new ApplicationSubmitted($application->id)));

                return $this->loadApplication($application, candidateSafe: true);
            });
        } catch (Throwable $exception) {
            if ($createdSnapshot !== null && ! $databaseCommitted) {
                $this->applicationSnapshotService->cleanupSnapshotFile($createdSnapshot);
            }

            throw $exception;
        }
    }

    private function assertJobAcceptsApplications(JobPosting $jobPosting): void
    {
        if ($jobPosting->status !== 'open') {
            throw ValidationException::withMessages([
                'job_posting_id' => [__('jobs.open_only')],
            ]);
        }

        $this->companyAccessService->assertRecruitmentAvailable($jobPosting);

        if ($jobPosting->isApplicationDeadlinePassed()) {
            throw new JobPostingOperationException(__('domain_errors.JOB_APPLICATION_DEADLINE_PASSED'), 'JOB_APPLICATION_DEADLINE_PASSED',
                409,
            );
        }
    }

    public function changeStatus(User $user, JobApplication $jobApplication, string $targetStatusSlug, ?string $note = null): JobApplication
    {
        if ($targetStatusSlug === 'need_more_information') {
            throw new ApplicationInformationRequestException(__('domain_errors.INFORMATION_REQUEST_ENDPOINT_REQUIRED'), 'INFORMATION_REQUEST_ENDPOINT_REQUIRED',
            );
        }
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
            if ($fromStatus->slug === 'need_more_information') {
                throw new ApplicationInformationRequestException(__('domain_errors.INFORMATION_RESPONSE_REQUIRED'), 'INFORMATION_RESPONSE_REQUIRED',
                );
            }
            $this->validateTransition($fromStatus->slug, $targetStatusSlug);

            $toStatus = $this->statusBySlug($targetStatusSlug);

            $application->forceFill([
                'application_status_id' => $toStatus->id,
            ])->save();

            $history = $this->recordHistory($application, $fromStatus, $toStatus, $user, $note);

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
                $history->id,
            )));

            return $this->loadApplication($application);
        });
    }

    public function grantTestRetake(User $user, JobApplication $jobApplication, string $note): JobApplication
    {
        return DB::transaction(function () use ($user, $jobApplication, $note): JobApplication {
            $application = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($jobApplication->id);

            if ($application->applicationStatus?->slug !== 'test_completed') {
                throw ValidationException::withMessages([
                    'status' => ['A test retake can only reopen an application from test_completed.'],
                ]);
            }

            $pending = $this->statusBySlug('test_pending');
            $this->recordHistory($application, $application->applicationStatus, $pending, $user, $note);
            $application->forceFill(['application_status_id' => $pending->id])->save();

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

            if ($fromStatus->slug === 'need_more_information') {
                ApplicationInformationRequest::query()
                    ->where('job_application_id', $application->id)
                    ->where('status', ApplicationInformationRequestStatus::PENDING->value)
                    ->update([
                        'status' => ApplicationInformationRequestStatus::CANCELLED->value,
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $user->id,
                        'updated_at' => now(),
                    ]);
            }
            $withdrawnStatus = $this->statusBySlug(self::STATUS_WITHDRAWN);

            $application->forceFill([
                'application_status_id' => $withdrawnStatus->id,
            ])->save();

            $history = $this->recordHistory($application, $fromStatus, $withdrawnStatus, $user, $note);

            DB::afterCommit(fn (): array => event(new ApplicationStatusChanged(
                $application->id,
                $fromStatus->slug,
                $withdrawnStatus->slug,
                $user->id,
                $note,
                $history->id,
            )));

            return $this->loadApplication($application, candidateSafe: true);
        });
    }

    public function validateTransition(string $currentSlug, string $targetSlug): void
    {
        if (in_array($currentSlug, self::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => [__('applications.terminal_immutable')],
            ]);
        }

        $allowedTransitions = self::VALID_TRANSITIONS[$currentSlug] ?? [];

        if (! in_array($targetSlug, $allowedTransitions, true)) {
            throw ValidationException::withMessages([
                'status' => [__('validation_domain.application_transition', [
                    'from' => $currentSlug,
                    'to' => $targetSlug,
                ])],
            ]);
        }
    }

    public function recordHistory(
        JobApplication $jobApplication,
        ?ApplicationStatus $from,
        ApplicationStatus $to,
        User $actor,
        ?string $note = null,
    ): ApplicationStatusHistory {
        return ApplicationStatusHistory::create([
            'job_application_id' => $jobApplication->id,
            'from_application_status_id' => $from?->id,
            'to_application_status_id' => $to->id,
            'changed_by_user_id' => $actor->id,
            'note' => $note,
        ]);
    }

    public function requestMoreInformation(User $actor, JobApplication $application): void
    {
        $from = $application->applicationStatus;
        $this->validateTransition($from->slug, 'need_more_information');
        $this->transitionInformationStatus($actor, $application, $from, $this->statusBySlug('need_more_information'));
    }

    public function submitRequestedInformation(User $actor, JobApplication $application): void
    {
        $from = $application->applicationStatus;
        if ($from->slug !== 'need_more_information') {
            throw new ApplicationInformationRequestException(__('domain_errors.APPLICATION_INFORMATION_REQUEST_NOT_PENDING'), 'APPLICATION_INFORMATION_REQUEST_NOT_PENDING');
        }
        $this->transitionInformationStatus($actor, $application, $from, $this->statusBySlug('under_review'));
    }

    public function cancelInformationRequest(User $actor, JobApplication $application, string $targetStatus, ?string $note = null): void
    {
        $from = $application->applicationStatus;
        if ($from->slug !== 'need_more_information') {
            throw new ApplicationInformationRequestException(__('domain_errors.APPLICATION_INFORMATION_REQUEST_NOT_PENDING'), 'APPLICATION_INFORMATION_REQUEST_NOT_PENDING');
        }
        $target = ApplicationStatus::query()->where('slug', $targetStatus)->first() ?? $this->statusBySlug('under_review');
        $this->transitionInformationStatus($actor, $application, $from, $target, $note);
    }

    private function transitionInformationStatus(User $actor, JobApplication $application, ApplicationStatus $from, ApplicationStatus $to, ?string $note = null): void
    {
        $application->forceFill(['application_status_id' => $to->id])->save();
        $application->setRelation('applicationStatus', $to);
        $this->recordHistory($application, $from, $to, $actor, $note);
    }

    public function checkDuplicateApplication(JobPosting $jobPosting, JobSeekerProfile $profile): void
    {
        $exists = JobApplication::query()
            ->where('job_posting_id', $jobPosting->id)
            ->where('job_seeker_profile_id', $profile->id)
            ->whereHas('applicationStatus', fn ($query) => $query->whereNotIn('slug', self::TERMINAL_STATUSES))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'job_posting_id' => [__('jobs.already_applied')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{applications: LengthAwarePaginator<int, JobApplication>, counts: array<string, int>}
     */
    public function getMyApplications(User $user, array $filters = []): array
    {
        return $this->applicationPageService->getMyApplications($user, $filters);
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

    public function getApplication(User $viewer, JobApplication $jobApplication): JobApplication
    {
        $application = $this->loadApplication(
            $jobApplication,
            candidateSafe: $viewer->role === UserRole::JOB_SEEKER,
        );

        if ($viewer->role === UserRole::JOB_SEEKER) {
            $application->loadMissing([
                'latestStatusHistory',
                'latestTestAssignment.testAttempt',
                'upcomingInterview',
                'latestInterview',
            ]);
            $this->applicationPageService->attachPresentation($application, $viewer);
        }

        return $application;
    }

    private function resolveApplicationCV(User $user, JobSeekerProfile $profile, mixed $requestedId): CVFile
    {
        $cvId = $requestedId === null ? $profile->primary_cv_file_id : (int) $requestedId;
        if ($cvId === null) {
            throw new CVLifecycleException(__('domain_errors.PRIMARY_CV_REQUIRED'), 'PRIMARY_CV_REQUIRED', 422);
        }

        $cvFile = CVFile::query()->lockForUpdate()->find($cvId);
        if (! $cvFile instanceof CVFile || $cvFile->user_id !== $user->id) {
            throw new CVLifecycleException(__('domain_errors.CV_NOT_OWNED'), 'CV_NOT_OWNED', 403);
        }
        if ($cvFile->archived_at !== null) {
            throw new CVLifecycleException(__('domain_errors.CV_ARCHIVED'), 'CV_ARCHIVED');
        }
        if (! $this->privateStorage->exists($cvFile->disk, $cvFile->stored_path)) {
            throw new CVLifecycleException(__('domain_errors.CV_FILE_UNAVAILABLE'), 'CV_FILE_UNAVAILABLE', 404);
        }
        if (! $cvFile->isUsableForApplication()) {
            throw new CVLifecycleException(__('domain_errors.CV_NOT_USABLE_FOR_APPLICATION'), 'CV_NOT_USABLE_FOR_APPLICATION');
        }

        $legacyCVWithoutReviewMetadata = $cvFile->status === 'parsed'
            && $cvFile->review_mode === null
            && $cvFile->review_status === null;
        if (! $cvFile->isConfirmedUsableForApplication() && ! $legacyCVWithoutReviewMetadata) {
            throw new CVLifecycleException(__('domain_errors.CV_NOT_USABLE_FOR_APPLICATION'), 'CV_NOT_USABLE_FOR_APPLICATION');
        }
        if (! $legacyCVWithoutReviewMetadata && (int) $profile->primary_cv_file_id !== $cvFile->id) {
            throw new CVLifecycleException(
                __('domain_errors.APPLICATION_CURRENT_CV_REQUIRED'),
                'APPLICATION_CURRENT_CV_REQUIRED',
                422,
            );
        }

        return $cvFile;
    }

    private function statusBySlug(string $slug): ApplicationStatus
    {
        return ApplicationStatus::query()
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function loadApplication(JobApplication $jobApplication, bool $candidateSafe = false): JobApplication
    {
        $application = $jobApplication->load($this->applicationRelations($candidateSafe, includeScreeningAnswers: true, includeSnapshotDetails: true));
        $application->setAttribute('include_submitted_snapshot', true);

        return $application;
    }

    /**
     * @return array<int, string>
     */
    private function applicationRelations(bool $candidateSafe = false, bool $includeScreeningAnswers = false, bool $includeSnapshotDetails = false): array
    {
        $relations = [
            'jobPosting.company',
            'jobPosting.city',
            'jobPosting.skills',
            'selectedCvFile',
            'applicationStatus',
            'statusHistory.fromStatus',
            'statusHistory.toStatus',
            'latestInformationRequest.response',
            $includeSnapshotDetails
                ? 'snapshot'
                : 'snapshot:id,job_application_id,source_cv_file_id,cv_original_name,cv_mime_type,cv_extension,cv_size_bytes,captured_at,origin,accuracy',
        ];

        if ($includeScreeningAnswers) {
            $relations[] = 'screeningQuestionSnapshots.options';
            $relations[] = 'screeningQuestionSnapshots.answer.selectedOptions.option';
        }

        if (! $candidateSafe) {
            $relations[] = 'statusHistory.changedBy';
        }

        return $relations;
    }
}
