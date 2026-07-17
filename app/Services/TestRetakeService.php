<?php

namespace App\Services;

use App\Events\TestRetakeGranted;
use App\Models\ApplicationTestAssignment;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TestRetakeService
{
    private const DISALLOWED_APPLICATION_STATUSES = [
        'accepted',
        'rejected',
        'withdrawn',
        'interview_pending',
        'interview_scheduled',
        'interview_completed',
        'final_review',
    ];

    public function __construct(
        private readonly TestAssignmentDeadlineService $deadlineService,
        private readonly ApplicationWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /** @param array{max_attempts:int, reason?:string|null} $data */
    public function updatePolicy(User $actor, ApplicationTestAssignment $assignment, array $data): ApplicationTestAssignment
    {
        return DB::transaction(function () use ($actor, $assignment, $data): ApplicationTestAssignment {
            $root = $this->lockedRoot($assignment);
            $application = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($root->job_application_id);

            if (in_array($application->applicationStatus?->slug, ['accepted', 'rejected', 'withdrawn'], true)) {
                throw new ConflictHttpException('The application is no longer eligible for a test retake policy change.');
            }

            $series = $this->lockedSeries($root);
            $newMaximum = (int) $data['max_attempts'];
            if ($newMaximum <= $root->max_attempts) {
                throw ValidationException::withMessages([
                    'max_attempts' => ['The maximum attempts value can only be increased.'],
                ]);
            }
            if ($newMaximum < $series->count()) {
                throw ValidationException::withMessages([
                    'max_attempts' => ['The maximum attempts value cannot be lower than attempts already used.'],
                ]);
            }

            $previousMaximum = $root->max_attempts;
            ApplicationTestAssignment::query()
                ->whereIn('id', $series->pluck('id'))
                ->update(['max_attempts' => $newMaximum]);

            $reason = $this->normalizeReason($data['reason'] ?? null);
            $this->auditLogService->record(
                'test_assignment.retake_policy_updated',
                $actor,
                ApplicationTestAssignment::class,
                $root->id,
                ['max_attempts' => $previousMaximum],
                ['max_attempts' => $newMaximum],
                [
                    'application_id' => $root->job_application_id,
                    'test_id' => $root->test_id,
                    'series_root_assignment_id' => $root->id,
                    'actor_id' => $actor->id,
                    'reason_present' => $reason !== null,
                ],
            );

            return $this->loadAssignment($root->refresh());
        });
    }

    /** @param array{deadline_at?:string|null, reason?:string|null, instructions?:string|null} $data */
    public function grant(User $actor, ApplicationTestAssignment $assignment, array $data): ApplicationTestAssignment
    {
        try {
            return DB::transaction(function () use ($actor, $assignment, $data): ApplicationTestAssignment {
                $root = $this->lockedRoot($assignment);
                $application = JobApplication::query()
                    ->with('applicationStatus')
                    ->lockForUpdate()
                    ->findOrFail($root->job_application_id);
                $series = $this->lockedSeries($root);
                $latest = $series->sortByDesc('attempt_number')->first();

                if (! $latest instanceof ApplicationTestAssignment || $latest->id !== $assignment->id) {
                    throw new ConflictHttpException('This assignment is not the latest assignment in the retake series.');
                }

                $latest->loadMissing('testAttempt');
                if ($latest->testAttempt?->submitted_at === null) {
                    if ($latest->isExpired()) {
                        throw new ConflictHttpException('An expired unsubmitted assignment should be extended instead of creating a retake.');
                    }
                    throw new ConflictHttpException('A retake can only be granted after the current attempt has been submitted.');
                }

                if ($application->applicationStatus?->slug !== 'test_completed'
                    || in_array($application->applicationStatus?->slug, self::DISALLOWED_APPLICATION_STATUSES, true)) {
                    throw new ConflictHttpException('The application is no longer eligible for a test retake.');
                }

                if ($series->count() >= $root->max_attempts) {
                    throw new ConflictHttpException('The maximum number of test attempts has been reached.');
                }

                $deadline = $this->deadlineService->normalizeInitialDeadline($data['deadline_at'] ?? null);
                $attemptNumber = $latest->attempt_number + 1;
                $reason = $this->normalizeReason($data['reason'] ?? null);
                $instructions = array_key_exists('instructions', $data)
                    ? $this->normalizeInstructions($data['instructions'])
                    : $latest->note;

                $retake = ApplicationTestAssignment::query()->create([
                    'series_root_assignment_id' => $root->id,
                    'previous_assignment_id' => $latest->id,
                    'attempt_number' => $attemptNumber,
                    'max_attempts' => $root->max_attempts,
                    'job_application_id' => $root->job_application_id,
                    'test_id' => $root->test_id,
                    'assigned_by_user_id' => $actor->id,
                    'retake_granted_by_user_id' => $actor->id,
                    'note' => $instructions,
                    'retake_reason' => $reason,
                    'assigned_at' => now(),
                    'deadline_at' => $deadline,
                ]);

                $this->workflowService->grantTestRetake(
                    $actor,
                    $application,
                    "Test retake granted: assignment {$latest->id} to {$retake->id}, attempt {$attemptNumber}.",
                );

                $this->auditLogService->record(
                    'test_assignment.retake_granted',
                    $actor,
                    ApplicationTestAssignment::class,
                    $retake->id,
                    null,
                    [
                        'job_application_id' => $retake->job_application_id,
                        'test_id' => $retake->test_id,
                        'attempt_number' => $attemptNumber,
                        'max_attempts' => $root->max_attempts,
                        'deadline_at' => $deadline?->toISOString(),
                    ],
                    [
                        'application_id' => $retake->job_application_id,
                        'test_id' => $retake->test_id,
                        'series_root_assignment_id' => $root->id,
                        'previous_assignment_id' => $latest->id,
                        'new_assignment_id' => $retake->id,
                        'attempt_number' => $attemptNumber,
                        'max_attempts' => $root->max_attempts,
                        'actor_id' => $actor->id,
                        'deadline_at' => $deadline?->toISOString(),
                        'reason_present' => $reason !== null,
                    ],
                );

                DB::afterCommit(fn (): array => event(new TestRetakeGranted($retake->id)));

                return $this->loadAssignment($retake);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw new ConflictHttpException('A newer retake assignment already exists.');
            }

            throw $exception;
        }
    }

    public function assertLatestCanStart(ApplicationTestAssignment $assignment): void
    {
        if ($assignment->nextAssignment()->exists()) {
            throw new ConflictHttpException('This test assignment has been superseded by a newer retake assignment.');
        }

        $assignment->loadMissing('jobApplication.applicationStatus');
        if ($assignment->jobApplication->applicationStatus?->slug !== 'test_pending') {
            throw new ConflictHttpException('Only the current pending test assignment can be started.');
        }
    }

    /** @return array{root:ApplicationTestAssignment, assignments:Collection<int, ApplicationTestAssignment>} */
    public function getSeries(ApplicationTestAssignment $assignment): array
    {
        $rootId = $assignment->seriesRootId();
        $root = ApplicationTestAssignment::query()->findOrFail($rootId);
        $assignments = $this->seriesQuery($root)
            ->with([
                'testAttempt',
                'retakeGrantedBy',
                'deadlineChanges',
            ])
            ->orderBy('attempt_number')
            ->get();

        return ['root' => $root, 'assignments' => $assignments];
    }

    private function lockedRoot(ApplicationTestAssignment $assignment): ApplicationTestAssignment
    {
        return ApplicationTestAssignment::query()
            ->lockForUpdate()
            ->findOrFail($assignment->seriesRootId());
    }

    /** @return Collection<int, ApplicationTestAssignment> */
    private function lockedSeries(ApplicationTestAssignment $root): Collection
    {
        return $this->seriesQuery($root)->lockForUpdate()->get();
    }

    private function seriesQuery(ApplicationTestAssignment $root)
    {
        return ApplicationTestAssignment::query()
            ->where(function ($query) use ($root): void {
                $query->whereKey($root->id)
                    ->orWhere('series_root_assignment_id', $root->id);
            });
    }

    private function normalizeReason(mixed $reason): ?string
    {
        $value = trim((string) ($reason ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeInstructions(mixed $instructions): ?string
    {
        $value = trim((string) ($instructions ?? ''));

        return $value === '' ? null : $value;
    }

    private function loadAssignment(ApplicationTestAssignment $assignment): ApplicationTestAssignment
    {
        return $assignment->load([
            'test',
            'assignedBy',
            'retakeGrantedBy',
            'testAttempt',
            'deadlineChanges.changedBy',
            'jobApplication.applicationStatus',
            'jobApplication.jobPosting.company',
            'jobApplication.jobSeekerProfile.user',
        ]);
    }
}
