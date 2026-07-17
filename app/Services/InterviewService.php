<?php

namespace App\Services;

use App\Enums\InterviewAttendanceStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use App\Enums\InterviewType;
use App\Enums\UserRole;
use App\Events\InterviewAttendanceUpdated;
use App\Events\InterviewCancelled;
use App\Events\InterviewCompleted;
use App\Events\InterviewConfirmed;
use App\Events\InterviewEvaluated;
use App\Events\InterviewNoShow;
use App\Events\InterviewRescheduled;
use App\Events\InterviewScheduled;
use App\Events\InterviewUpdated;
use App\Exceptions\InterviewLifecycleException;
use App\Models\AuditLog;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\InterviewScheduleChange;
use App\Models\InterviewStatusHistory;
use App\Models\JobApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InterviewService
{
    private const ACTIVE_STATUSES = ['scheduled', 'confirmed', 'rescheduled'];

    private const TERMINAL_APPLICATION_STATUSES = ['accepted', 'rejected', 'withdrawn'];

    private const TRANSITIONS = [
        'scheduled' => ['confirmed', 'rescheduled', 'cancelled', 'no_show'],
        'confirmed' => ['rescheduled', 'cancelled', 'completed', 'no_show'],
        'rescheduled' => ['confirmed', 'rescheduled', 'cancelled', 'completed', 'no_show'],
        'completed' => ['evaluated'],
        'cancelled' => [],
        'no_show' => [],
        'evaluated' => [],
    ];

    public function __construct(
        private readonly ApplicationWorkflowService $applicationWorkflowService,
        private readonly AuditLogService $auditLogService,
        private readonly CompanyRecruitmentAccessService $companyAccessService,
    ) {}

    /** @param array<string, mixed> $data */
    public function createInterview(User $actor, JobApplication $application, array $data): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($application);

        return DB::transaction(function () use ($actor, $application, $data): Interview {
            $jobApplication = JobApplication::query()->with('applicationStatus')->lockForUpdate()->findOrFail($application->id);
            if (in_array($jobApplication->applicationStatus?->slug, self::TERMINAL_APPLICATION_STATUSES, true)) {
                $this->fail('Interviews cannot be scheduled for a terminal application.', 'INTERVIEW_INVALID_STATUS_TRANSITION', 409);
            }

            $schedule = $this->validatedSchedule($data, true);
            $type = InterviewType::from($data['type'])->value;
            $this->assertNoActiveInterviewForType($jobApplication->id, $type);

            $interview = Interview::query()->create([
                'job_application_id' => $jobApplication->id,
                'scheduled_by_user_id' => $actor->id,
                'interview_type' => $type,
                'status' => InterviewStatus::SCHEDULED->value,
                'scheduled_at' => $schedule['start'],
                'scheduled_end_at' => $schedule['end'],
                'duration_minutes' => $schedule['start']->diffInMinutes($schedule['end']),
                'interview_mode' => $schedule['mode'],
                'location' => $schedule['location'],
                'meeting_link' => $schedule['meeting_link'],
                'candidate_message' => $data['candidate_message'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'note' => $data['internal_note'] ?? null,
                'candidate_attendance_status' => InterviewAttendanceStatus::PENDING->value,
                'interviewer_attendance_status' => InterviewAttendanceStatus::PENDING->value,
            ]);
            $history = $this->recordTransition($interview, null, InterviewStatus::SCHEDULED->value, $actor, null);
            $this->audit('interview.scheduled', $actor, $interview, null, $this->auditState($interview));

            if ($jobApplication->applicationStatus?->slug !== 'interview_scheduled') {
                $this->applicationWorkflowService->changeStatus($actor, $jobApplication, 'interview_scheduled', 'Interview scheduled for candidate.');
            }

            DB::afterCommit(fn (): array => event(new InterviewScheduled($interview->id, $history->id)));

            return $this->loadInterview($interview, true);
        });
    }

    /** @return Collection<int, Interview> */
    public function getApplicationInterviews(JobApplication $application): Collection
    {
        return Interview::query()->with($this->interviewRelations(true))->where('job_application_id', $application->id)->latest('scheduled_at')->latest('id')->get();
    }

    /** @return LengthAwarePaginator<int, Interview> */
    public function getMyInterviews(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $profileId = $user->jobSeekerProfile?->id;

        return Interview::query()->with($this->interviewRelations(true, true))
            ->whereHas('jobApplication', fn ($query) => $query->where('job_seeker_profile_id', $profileId))
            ->latest('scheduled_at')->latest('id')->paginate($perPage);
    }

    public function getInterview(Interview $interview, User $viewer): Interview
    {
        return $this->loadInterview($interview, true, $viewer->role === UserRole::JOB_SEEKER);
    }

    /** @param array<string, mixed> $data */
    public function updateInterview(User $actor, Interview $interview, array $data): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview, $data): Interview {
            JobApplication::query()->lockForUpdate()->findOrFail($interview->job_application_id);
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            if ($locked->status !== InterviewStatus::SCHEDULED->value) {
                $this->fail('Only an unconfirmed scheduled interview can be edited.', 'INTERVIEW_INVALID_STATUS_TRANSITION');
            }

            $before = $locked->only(['interview_type', 'candidate_message', 'internal_note']);
            if (array_key_exists('type', $data) && $data['type'] !== $locked->interview_type) {
                $this->assertNoActiveInterviewForType($locked->job_application_id, $data['type'], $locked->id);
                $locked->interview_type = $data['type'];
            }
            if (array_key_exists('candidate_message', $data)) {
                $locked->candidate_message = $data['candidate_message'];
            }
            if (array_key_exists('internal_note', $data)) {
                $locked->internal_note = $data['internal_note'];
                $locked->note = $data['internal_note'];
            }

            if (! $locked->isDirty()) {
                return $this->loadInterview($locked, true);
            }

            $locked->save();
            $after = $locked->only(array_keys($before));
            $audit = $this->audit('interview.updated', $actor, $locked, $before, $after);
            $occurrence = $audit?->id ?? 'state-'.hash('sha256', json_encode($after, JSON_THROW_ON_ERROR));
            DB::afterCommit(fn (): array => event(new InterviewUpdated($locked->id, $occurrence)));

            return $this->loadInterview($locked, true);
        });
    }

    public function confirmInterview(User $actor, Interview $interview): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview): Interview {
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            if ($locked->status === InterviewStatus::CONFIRMED->value) {
                $this->fail('This interview is already confirmed.', 'INTERVIEW_ALREADY_CONFIRMED');
            }
            if (! in_array($locked->status, [InterviewStatus::SCHEDULED->value, InterviewStatus::RESCHEDULED->value], true)
                || $locked->scheduled_end_at === null
                || now()->gte($locked->scheduled_end_at)) {
                $this->fail('This interview cannot be confirmed in its current state.', 'INTERVIEW_CONFIRMATION_NOT_ALLOWED');
            }

            $from = $locked->status;
            $this->assertTransition($from, InterviewStatus::CONFIRMED->value);
            $locked->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_user_id' => $actor->id])->save();
            $history = $this->recordTransition($locked, $from, 'confirmed', $actor, null);
            $this->audit('interview.confirmed', $actor, $locked, ['status' => $from], ['status' => 'confirmed']);
            DB::afterCommit(fn (): array => event(new InterviewConfirmed($locked->id, $history->id)));

            return $this->loadInterview($locked, true, true);
        });
    }

    /** @param array<string, mixed> $data */
    public function rescheduleInterview(User $actor, Interview $interview, array $data): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview, $data): Interview {
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            if (! in_array($locked->status, self::ACTIVE_STATUSES, true)) {
                $this->fail('This interview cannot be rescheduled.', 'INTERVIEW_RESCHEDULE_NOT_ALLOWED');
            }
            $schedule = $this->validatedSchedule($data, true);
            $same = $locked->scheduled_at?->equalTo($schedule['start'])
                && $locked->scheduled_end_at?->equalTo($schedule['end'])
                && $locked->interview_mode === $schedule['mode']
                && $locked->meeting_link === $schedule['meeting_link']
                && $locked->location === $schedule['location'];
            if ($same) {
                $this->fail('The new interview schedule must contain a material change.', 'INTERVIEW_RESCHEDULE_NOT_ALLOWED', 422);
            }

            $change = InterviewScheduleChange::query()->create([
                'interview_id' => $locked->id,
                'previous_start_at' => $locked->scheduled_at,
                'previous_end_at' => $locked->scheduled_end_at,
                'new_start_at' => $schedule['start'],
                'new_end_at' => $schedule['end'],
                'previous_mode' => $locked->interview_mode,
                'new_mode' => $schedule['mode'],
                'previous_meeting_link' => $locked->meeting_link,
                'new_meeting_link' => $schedule['meeting_link'],
                'previous_location_text' => $locked->location,
                'new_location_text' => $schedule['location'],
                'changed_by_user_id' => $actor->id,
                'reason' => trim($data['reason']),
            ]);
            $from = $locked->status;
            $this->assertTransition($from, 'rescheduled');
            $locked->forceFill([
                'status' => 'rescheduled', 'scheduled_at' => $schedule['start'], 'scheduled_end_at' => $schedule['end'],
                'duration_minutes' => $schedule['start']->diffInMinutes($schedule['end']), 'interview_mode' => $schedule['mode'],
                'meeting_link' => $schedule['meeting_link'], 'location' => $schedule['location'],
                'confirmed_at' => null, 'confirmed_by_user_id' => null,
                'candidate_attendance_status' => 'pending', 'interviewer_attendance_status' => 'pending',
                'attendance_recorded_at' => null, 'attendance_recorded_by_user_id' => null, 'attendance_note' => null,
            ])->save();
            $history = $this->recordTransition($locked, $from, 'rescheduled', $actor, trim($data['reason']), ['schedule_change_id' => $change->id]);
            $this->audit('interview.rescheduled', $actor, $locked, ['status' => $from], $this->auditState($locked), ['schedule_change_id' => $change->id]);
            DB::afterCommit(fn (): array => event(new InterviewRescheduled($locked->id, $change->id)));

            return $this->loadInterview($locked, true);
        });
    }

    public function cancelInterview(User $actor, Interview $interview, string $reason, ?string $candidateMessage = null): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview, $reason, $candidateMessage): Interview {
            $application = JobApplication::query()->with('applicationStatus')->lockForUpdate()->findOrFail($interview->job_application_id);
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            if (! in_array($locked->status, self::ACTIVE_STATUSES, true)) {
                $this->fail('Only an active interview can be cancelled.', 'INTERVIEW_CANCELLATION_NOT_ALLOWED');
            }
            $from = $locked->status;
            $this->assertTransition($from, 'cancelled');
            $locked->forceFill([
                'status' => 'cancelled', 'cancellation_reason' => trim($reason), 'cancellation_message' => $candidateMessage,
                'cancelled_at' => now(), 'cancelled_by_user_id' => $actor->id,
            ])->save();
            $history = $this->recordTransition($locked, $from, 'cancelled', $actor, trim($reason));
            $this->audit('interview.cancelled', $actor, $locked, ['status' => $from], ['status' => 'cancelled']);
            $this->syncApplicationAfterClosedInterview($actor, $application);
            DB::afterCommit(fn (): array => event(new InterviewCancelled($application->id, $locked->id, $locked->scheduled_at?->toISOString(), $history->id, $candidateMessage)));

            return $this->loadInterview($locked, true);
        });
    }

    public function deleteInterview(User $actor, Interview $interview): void
    {
        $this->cancelInterview($actor, $interview, 'Cancelled through the legacy delete endpoint.');
    }

    /** @param array<string, mixed> $data */
    public function updateAttendance(User $actor, Interview $interview, array $data): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview, $data): Interview {
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            if (! in_array($locked->status, self::ACTIVE_STATUSES, true) || now()->lt($locked->scheduled_at)) {
                $this->fail('Attendance cannot be recorded yet or in this state.', 'INTERVIEW_ATTENDANCE_NOT_READY');
            }
            $before = $locked->only(['candidate_attendance_status', 'interviewer_attendance_status', 'attendance_note']);
            $after = ['candidate_attendance_status' => $data['candidate_status'], 'interviewer_attendance_status' => $data['interviewer_status'], 'attendance_note' => $data['note'] ?? null];
            if ($before === $after) {
                return $this->loadInterview($locked, true);
            }
            $locked->forceFill($after + ['attendance_recorded_at' => now(), 'attendance_recorded_by_user_id' => $actor->id])->save();
            $audit = $this->audit('interview.attendance_updated', $actor, $locked, $before, $after);
            $occurrence = $audit?->id ?? 'state-'.hash('sha256', json_encode($after, JSON_THROW_ON_ERROR));
            DB::afterCommit(fn (): array => event(new InterviewAttendanceUpdated($locked->id, $occurrence)));

            return $this->loadInterview($locked, true);
        });
    }

    public function markNoShow(User $actor, Interview $interview, string $party, string $reason): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview, $party, $reason): Interview {
            $application = JobApplication::query()->with('applicationStatus')->lockForUpdate()->findOrFail($interview->job_application_id);
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            if (! in_array($locked->status, self::ACTIVE_STATUSES, true) || now()->lt($locked->scheduled_at)) {
                $this->fail('This interview cannot be marked as no show.', 'INTERVIEW_NO_SHOW_NOT_ALLOWED');
            }
            $from = $locked->status;
            $this->assertTransition($from, 'no_show');
            $attendance = [
                'candidate_attendance_status' => in_array($party, ['candidate', 'both'], true) ? 'absent' : 'present',
                'interviewer_attendance_status' => in_array($party, ['interviewer', 'both'], true) ? 'absent' : 'present',
            ];
            $locked->forceFill($attendance + [
                'status' => 'no_show', 'attendance_recorded_at' => now(), 'attendance_recorded_by_user_id' => $actor->id,
                'attendance_note' => trim($reason),
            ])->save();
            $history = $this->recordTransition($locked, $from, 'no_show', $actor, trim($reason), ['party' => $party]);
            $this->audit('interview.no_show', $actor, $locked, ['status' => $from], ['status' => 'no_show'] + $attendance, ['party' => $party]);
            if ($application->applicationStatus?->slug !== 'interview_completed') {
                $this->applicationWorkflowService->changeStatus($actor, $application, 'interview_completed', 'Interview closed as no show.');
            }
            DB::afterCommit(fn (): array => event(new InterviewNoShow($locked->id, $history->id)));

            return $this->loadInterview($locked, true);
        });
    }

    public function completeInterview(User $actor, Interview $interview, ?string $completionNote): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview, $completionNote): Interview {
            $application = JobApplication::query()->with('applicationStatus')->lockForUpdate()->findOrFail($interview->job_application_id);
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            if ($locked->status !== 'confirmed' || now()->lt($locked->scheduled_at)) {
                $this->fail('Only a started, confirmed interview can be completed.', 'INTERVIEW_COMPLETION_NOT_ALLOWED');
            }
            if ($locked->candidate_attendance_status !== 'present' || $locked->interviewer_attendance_status !== 'present') {
                $this->fail('Present attendance for both parties is required before completion.', 'INTERVIEW_COMPLETION_NOT_ALLOWED');
            }
            $from = $locked->status;
            $this->assertTransition($from, 'completed');
            $locked->forceFill(['status' => 'completed', 'completion_note' => $completionNote, 'completed_at' => now(), 'completed_by_user_id' => $actor->id])->save();
            $history = $this->recordTransition($locked, $from, 'completed', $actor, null);
            $this->audit('interview.completed', $actor, $locked, ['status' => $from], ['status' => 'completed']);
            if ($application->applicationStatus?->slug !== 'interview_completed') {
                $this->applicationWorkflowService->changeStatus($actor, $application, 'interview_completed', 'Interview completed for candidate.');
            }
            DB::afterCommit(fn (): array => event(new InterviewCompleted($locked->id, $history->id)));

            return $this->loadInterview($locked, true);
        });
    }

    /** @param array<string, mixed> $data */
    public function evaluateInterview(User $actor, Interview $interview, array $data): Interview
    {
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        return DB::transaction(function () use ($actor, $interview, $data): Interview {
            $application = JobApplication::query()->with('applicationStatus')->lockForUpdate()->findOrFail($interview->job_application_id);
            $locked = Interview::query()->with('evaluation')->lockForUpdate()->findOrFail($interview->id);
            if ($locked->status !== 'completed' || $locked->evaluation instanceof InterviewEvaluation) {
                $this->fail('Only a completed, unevaluated interview can be evaluated.', 'INTERVIEW_EVALUATION_NOT_ALLOWED');
            }
            $evaluation = InterviewEvaluation::query()->create([
                'interview_id' => $locked->id, 'evaluated_by_user_id' => $actor->id,
                'recommendation' => $data['recommendation'], 'overall_comment' => $data['overall_comment'] ?? null, 'evaluated_at' => now(),
            ]);
            foreach ($data['items'] as $index => $item) {
                $evaluation->items()->create(['criterion' => $item['criterion'], 'score' => $item['score'], 'comment' => $item['comment'] ?? null, 'sort_order' => $index + 1]);
            }
            $from = $locked->status;
            $this->assertTransition($from, 'evaluated');
            $locked->forceFill(['status' => 'evaluated'])->save();
            $history = $this->recordTransition($locked, $from, 'evaluated', $actor, null);
            $this->audit('interview.evaluated', $actor, $locked, ['status' => $from], ['status' => 'evaluated']);
            if ($locked->interview_type === InterviewType::FINAL->value && $application->applicationStatus?->slug !== 'final_review') {
                $this->applicationWorkflowService->changeStatus($actor, $application, 'final_review', 'Final interview evaluation completed.');
            }
            DB::afterCommit(fn (): array => event(new InterviewEvaluated($locked->id, $history->id)));

            return $this->loadInterview($locked->fresh(), true);
        });
    }

    /** @return LengthAwarePaginator<int, InterviewStatusHistory> */
    public function statusHistory(Interview $interview, int $perPage = 25): LengthAwarePaginator
    {
        return $interview->statusHistory()->with('changedBy:id,name,role')->paginate($perPage);
    }

    /** @return LengthAwarePaginator<int, InterviewScheduleChange> */
    public function scheduleHistory(Interview $interview, int $perPage = 25): LengthAwarePaginator
    {
        return $interview->scheduleChanges()->with('changedBy:id,name,role')->paginate($perPage);
    }

    private function loadInterview(Interview $interview, bool $includeApplicationContext = false, bool $candidateSafe = false): Interview
    {
        return $interview->load($this->interviewRelations($includeApplicationContext, $candidateSafe));
    }

    /** @return array<int, string> */
    private function interviewRelations(bool $includeApplicationContext = false, bool $candidateSafe = false): array
    {
        $relations = $candidateSafe ? [] : [
            'scheduledBy', 'confirmedBy', 'completedBy', 'cancelledBy', 'attendanceRecordedBy',
            'evaluation.evaluatedBy', 'evaluation.items', 'statusHistory.changedBy', 'scheduleChanges.changedBy',
        ];
        if ($includeApplicationContext) {
            array_push($relations, 'jobApplication.jobPosting.company', 'jobApplication.jobPosting.skills', 'jobApplication.selectedCvFile', 'jobApplication.applicationStatus');
            if (! $candidateSafe) {
                array_push($relations, 'jobApplication.jobSeekerProfile.user', 'jobApplication.jobSeekerProfile.skills', 'jobApplication.statusHistory.fromStatus', 'jobApplication.statusHistory.toStatus', 'jobApplication.statusHistory.changedBy');
            }
        }

        return $relations;
    }

    /** @param array<string, mixed> $data @return array{start: CarbonImmutable, end: CarbonImmutable, mode: string, location: ?string, meeting_link: ?string} */
    private function validatedSchedule(array $data, bool $future): array
    {
        try {
            $start = CarbonImmutable::parse($data['scheduled_start_at'])->utc();
            $end = CarbonImmutable::parse($data['scheduled_end_at'])->utc();
        } catch (\Throwable) {
            $this->fail('The interview time configuration is invalid.', 'INTERVIEW_TIME_INVALID', 422);
        }
        if (($future && $start->lte(now())) || $end->lte($start) || $start->diffInMinutes($end) > 480) {
            $this->fail('Interview times must be future, ordered, and no longer than eight hours.', 'INTERVIEW_TIME_INVALID', 422);
        }
        $mode = InterviewMode::from($data['mode'])->value;
        $meetingLink = isset($data['meeting_link']) ? trim((string) $data['meeting_link']) : null;
        $location = isset($data['location_text']) ? trim((string) $data['location_text']) : null;
        $meetingLink = $meetingLink === '' ? null : $meetingLink;
        $location = $location === '' ? null : $location;
        if (($mode === 'online' && $meetingLink === null) || ($mode === 'on_site' && $location === null)) {
            $this->fail('The interview mode configuration is incomplete.', 'INTERVIEW_MODE_CONFIGURATION_INVALID', 422);
        }

        return ['start' => $start, 'end' => $end, 'mode' => $mode, 'meeting_link' => $mode === 'online' ? $meetingLink : null, 'location' => $mode === 'on_site' ? $location : null];
    }

    private function assertNoActiveInterviewForType(int $applicationId, string $type, ?int $exceptId = null): void
    {
        $exists = Interview::query()->where('job_application_id', $applicationId)->where('interview_type', $type)
            ->whereIn('status', self::ACTIVE_STATUSES)->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))->exists();
        if ($exists) {
            $this->fail('An active interview of this type already exists for the application.', 'INTERVIEW_ALREADY_ACTIVE_FOR_TYPE');
        }
    }

    private function assertTransition(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            $this->fail("The interview transition from {$from} to {$to} is not allowed.", 'INTERVIEW_INVALID_STATUS_TRANSITION');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function recordTransition(Interview $interview, ?string $from, string $to, User $actor, ?string $reason, array $metadata = []): InterviewStatusHistory
    {
        return InterviewStatusHistory::query()->create([
            'interview_id' => $interview->id, 'from_status' => $from, 'to_status' => $to,
            'changed_by_user_id' => $actor->id, 'reason' => $reason,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private function syncApplicationAfterClosedInterview(User $actor, JobApplication $application): void
    {
        $active = Interview::query()->where('job_application_id', $application->id)->whereIn('status', self::ACTIVE_STATUSES)->exists();
        $completed = Interview::query()->where('job_application_id', $application->id)->whereIn('status', ['completed', 'evaluated', 'no_show'])->exists();
        $target = $active ? 'interview_scheduled' : ($completed ? 'interview_completed' : 'interview_pending');
        if ($application->applicationStatus?->slug !== $target) {
            $this->applicationWorkflowService->changeStatus($actor, $application, $target, 'Application interview status recalculated.');
        }
    }

    /** @return array<string, mixed> */
    private function auditState(Interview $interview): array
    {
        return [
            'interview_id' => $interview->id, 'application_id' => $interview->job_application_id,
            'status' => $interview->status, 'type' => $interview->interview_type, 'mode' => $interview->interview_mode,
            'scheduled_start_at' => $interview->scheduled_at?->toISOString(), 'scheduled_end_at' => $interview->scheduled_end_at?->toISOString(),
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after @param array<string, mixed> $metadata */
    private function audit(string $action, User $actor, Interview $interview, ?array $before, ?array $after, array $metadata = []): ?AuditLog
    {
        return $this->auditLogService->record($action, $actor, Interview::class, $interview->id, $before, $after, $metadata + ['application_id' => $interview->job_application_id, 'actor_id' => $actor->id]);
    }

    /** @param array<string, array<int, string>> $errors */
    private function fail(string $message, string $code, int $status = 409, array $errors = []): never
    {
        throw new InterviewLifecycleException($message, $code, $status, $errors);
    }
}
