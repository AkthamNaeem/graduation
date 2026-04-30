<?php

namespace App\Services;

use App\Events\InterviewEvaluated;
use App\Events\InterviewScheduled;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InterviewService
{
    private const STATUS_FINAL_REVIEW = 'final_review';

    private const STATUS_INTERVIEW_COMPLETED = 'interview_completed';

    private const STATUS_INTERVIEW_PENDING = 'interview_pending';

    private const STATUS_INTERVIEW_SCHEDULED = 'interview_scheduled';

    /**
     * @var array<int, string>
     */
    private const TERMINAL_STATUSES = [
        'accepted',
        'rejected',
        'withdrawn',
    ];

    public function __construct(
        private readonly ApplicationWorkflowService $applicationWorkflowService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createInterview(User $actor, JobApplication $application, array $data): Interview
    {
        return DB::transaction(function () use ($actor, $application, $data): Interview {
            $jobApplication = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($application->id);

            $statusSlug = $jobApplication->applicationStatus?->slug;

            if ($statusSlug !== null && in_array($statusSlug, self::TERMINAL_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'job_application_id' => ['Interviews cannot be created for rejected, withdrawn, or accepted applications.'],
                ]);
            }

            $hasActiveInterview = Interview::query()
                ->where('job_application_id', $jobApplication->id)
                ->whereNull('completed_at')
                ->exists();

            if ($hasActiveInterview) {
                throw ValidationException::withMessages([
                    'job_application_id' => ['Only one unfinished interview is allowed per application.'],
                ]);
            }

            $interview = Interview::create([
                'job_application_id' => $jobApplication->id,
                'scheduled_by_user_id' => $actor->id,
                'interview_type' => $data['interview_type'],
                'scheduled_at' => $data['scheduled_at'],
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'interview_mode' => $data['interview_mode'],
                'location' => $data['location'] ?? null,
                'meeting_link' => $data['meeting_link'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            if ($jobApplication->applicationStatus?->slug !== self::STATUS_INTERVIEW_SCHEDULED) {
                $this->applicationWorkflowService->changeStatus(
                    $actor,
                    $jobApplication,
                    self::STATUS_INTERVIEW_SCHEDULED,
                    'Interview scheduled for candidate.',
                );
            }

            DB::afterCommit(fn (): array => event(new InterviewScheduled($interview->id)));

            return $this->loadInterview($interview, includeApplicationContext: true);
        });
    }

    /**
     * @return Collection<int, Interview>
     */
    public function getApplicationInterviews(JobApplication $application): Collection
    {
        return Interview::query()
            ->with($this->interviewRelations(includeApplicationContext: true))
            ->where('job_application_id', $application->id)
            ->latest('scheduled_at')
            ->latest('id')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Interview>
     */
    public function getMyInterviews(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $profileId = $user->jobSeekerProfile?->id;

        return Interview::query()
            ->with($this->interviewRelations(includeApplicationContext: true))
            ->whereHas('jobApplication', function ($query) use ($profileId): void {
                $query->where('job_seeker_profile_id', $profileId);
            })
            ->latest('scheduled_at')
            ->latest('id')
            ->paginate($perPage);
    }

    public function getInterview(Interview $interview): Interview
    {
        return $this->loadInterview($interview, includeApplicationContext: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateInterview(User $actor, Interview $interview, array $data): Interview
    {
        return DB::transaction(function () use ($interview, $data): Interview {
            $lockedInterview = Interview::query()
                ->with('evaluation')
                ->lockForUpdate()
                ->findOrFail($interview->id);

            $this->ensureInterviewEditable($lockedInterview);

            $lockedInterview->forceFill([
                'interview_type' => $data['interview_type'],
                'scheduled_at' => $data['scheduled_at'],
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'interview_mode' => $data['interview_mode'],
                'location' => $data['location'] ?? null,
                'meeting_link' => $data['meeting_link'] ?? null,
                'note' => $data['note'] ?? null,
            ])->save();

            return $this->loadInterview($lockedInterview, includeApplicationContext: true);
        });
    }

    public function deleteInterview(User $actor, Interview $interview): void
    {
        DB::transaction(function () use ($actor, $interview): void {
            $lockedInterview = Interview::query()
                ->with(['jobApplication.applicationStatus', 'evaluation'])
                ->lockForUpdate()
                ->findOrFail($interview->id);

            $this->ensureInterviewEditable($lockedInterview);

            $application = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($lockedInterview->job_application_id);

            $lockedInterview->delete();

            $this->syncApplicationInterviewStatusAfterDeletion($actor, $application);
        });
    }

    public function completeInterview(User $actor, Interview $interview, ?string $completionNote): Interview
    {
        return DB::transaction(function () use ($actor, $interview, $completionNote): Interview {
            $lockedInterview = Interview::query()
                ->with(['jobApplication.applicationStatus', 'evaluation'])
                ->lockForUpdate()
                ->findOrFail($interview->id);

            if ($lockedInterview->completed_at !== null) {
                throw ValidationException::withMessages([
                    'interview_id' => ['This interview has already been completed.'],
                ]);
            }

            if ($lockedInterview->evaluation instanceof InterviewEvaluation) {
                throw ValidationException::withMessages([
                    'interview_id' => ['Evaluated interviews cannot be completed again.'],
                ]);
            }

            $lockedInterview->forceFill([
                'completion_note' => $completionNote,
                'completed_at' => now(),
                'completed_by_user_id' => $actor->id,
            ])->save();

            $jobApplication = $lockedInterview->jobApplication;

            if ($jobApplication->applicationStatus?->slug !== self::STATUS_INTERVIEW_COMPLETED) {
                $this->applicationWorkflowService->changeStatus(
                    $actor,
                    $jobApplication,
                    self::STATUS_INTERVIEW_COMPLETED,
                    'Interview completed for candidate.',
                );
            }

            return $this->loadInterview($lockedInterview, includeApplicationContext: true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function evaluateInterview(User $actor, Interview $interview, array $data): Interview
    {
        return DB::transaction(function () use ($actor, $interview, $data): Interview {
            $lockedInterview = Interview::query()
                ->with(['jobApplication.applicationStatus', 'evaluation'])
                ->lockForUpdate()
                ->findOrFail($interview->id);

            if ($lockedInterview->completed_at === null) {
                throw ValidationException::withMessages([
                    'interview_id' => ['Interviews must be completed before evaluation.'],
                ]);
            }

            if ($lockedInterview->evaluation instanceof InterviewEvaluation) {
                throw ValidationException::withMessages([
                    'interview_id' => ['This interview has already been evaluated.'],
                ]);
            }

            $evaluation = InterviewEvaluation::create([
                'interview_id' => $lockedInterview->id,
                'evaluated_by_user_id' => $actor->id,
                'recommendation' => $data['recommendation'],
                'overall_comment' => $data['overall_comment'] ?? null,
                'evaluated_at' => now(),
            ]);

            foreach ($data['items'] as $index => $item) {
                $evaluation->items()->create([
                    'criterion' => $item['criterion'],
                    'score' => $item['score'],
                    'comment' => $item['comment'] ?? null,
                    'sort_order' => $index + 1,
                ]);
            }

            $jobApplication = $lockedInterview->jobApplication;

            if ($jobApplication->applicationStatus?->slug !== self::STATUS_FINAL_REVIEW) {
                $this->applicationWorkflowService->changeStatus(
                    $actor,
                    $jobApplication,
                    self::STATUS_FINAL_REVIEW,
                    'Interview evaluation completed.',
                );
            }

            DB::afterCommit(fn (): array => event(new InterviewEvaluated($lockedInterview->id)));

            return $this->loadInterview($lockedInterview->fresh(), includeApplicationContext: true);
        });
    }

    private function loadInterview(Interview $interview, bool $includeApplicationContext = false): Interview
    {
        return $interview->load($this->interviewRelations($includeApplicationContext));
    }

    /**
     * @return array<int, string>
     */
    private function interviewRelations(bool $includeApplicationContext = false): array
    {
        $relations = [
            'scheduledBy',
            'completedBy',
            'evaluation.evaluatedBy',
            'evaluation.items',
        ];

        if ($includeApplicationContext) {
            $relations[] = 'jobApplication.jobPosting.company';
            $relations[] = 'jobApplication.jobPosting.skills';
            $relations[] = 'jobApplication.jobSeekerProfile.user';
            $relations[] = 'jobApplication.jobSeekerProfile.skills';
            $relations[] = 'jobApplication.applicationStatus';
            $relations[] = 'jobApplication.statusHistory.fromStatus';
            $relations[] = 'jobApplication.statusHistory.toStatus';
            $relations[] = 'jobApplication.statusHistory.changedBy';
        }

        return $relations;
    }

    private function ensureInterviewEditable(Interview $interview): void
    {
        if ($interview->completed_at !== null) {
            throw ValidationException::withMessages([
                'interview_id' => ['Completed interviews cannot be modified or deleted.'],
            ]);
        }

        if ($interview->evaluation instanceof InterviewEvaluation) {
            throw ValidationException::withMessages([
                'interview_id' => ['Evaluated interviews cannot be modified or deleted.'],
            ]);
        }
    }

    private function syncApplicationInterviewStatusAfterDeletion(User $actor, JobApplication $application): void
    {
        $remainingUnfinished = Interview::query()
            ->where('job_application_id', $application->id)
            ->whereNull('completed_at')
            ->exists();

        if ($remainingUnfinished) {
            $targetStatus = self::STATUS_INTERVIEW_SCHEDULED;
            $note = 'Interview status recalculated after interview deletion.';
        } else {
            $remainingCompletedUnevaluated = Interview::query()
                ->where('job_application_id', $application->id)
                ->whereNotNull('completed_at')
                ->whereDoesntHave('evaluation')
                ->exists();

            $targetStatus = $remainingCompletedUnevaluated
                ? self::STATUS_INTERVIEW_COMPLETED
                : self::STATUS_INTERVIEW_PENDING;

            $note = 'Interview status recalculated after interview deletion.';
        }

        if ($application->applicationStatus?->slug !== $targetStatus) {
            $this->applicationWorkflowService->changeStatus($actor, $application, $targetStatus, $note);
        }
    }
}
