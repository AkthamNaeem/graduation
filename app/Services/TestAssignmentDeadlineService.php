<?php

namespace App\Services;

use App\Events\TestAssignmentDeadlineExtended;
use App\Models\ApplicationTestAssignment;
use App\Models\ApplicationTestAssignmentDeadlineChange;
use App\Models\TestAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TestAssignmentDeadlineService
{
    private const TERMINAL_APPLICATION_STATUSES = ['accepted', 'rejected', 'withdrawn'];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function normalizeInitialDeadline(?string $deadline): ?CarbonImmutable
    {
        if ($deadline === null) {
            return null;
        }

        $normalized = CarbonImmutable::parse($deadline)->utc();
        if ($normalized->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'deadline_at' => ['The deadline must be a future date and time.'],
            ]);
        }

        return $normalized;
    }

    public function assertCanStart(ApplicationTestAssignment $assignment): void
    {
        $assignment->loadMissing('jobApplication.applicationStatus');
        if (in_array($assignment->jobApplication->applicationStatus?->slug, self::TERMINAL_APPLICATION_STATUSES, true)) {
            throw new ConflictHttpException('A test attempt cannot be started for a final application.');
        }

        if ($assignment->isExpired()) {
            throw new ConflictHttpException('This test assignment has expired and can no longer be started.');
        }
    }

    public function assertCanMutateAnswers(TestAttempt $attempt): void
    {
        $query = $attempt->applicationTestAssignment()->with('testAttempt');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $assignment = $query->firstOrFail();

        if ($assignment->isExpired()) {
            throw new ConflictHttpException('This test assignment has expired and its answers can no longer be modified.');
        }
    }

    public function assertCanSubmit(ApplicationTestAssignment $assignment): void
    {
        if ($assignment->isExpired()) {
            throw new ConflictHttpException('This test assignment has expired and can no longer be submitted.');
        }
    }

    /**
     * @param  array{deadline_at:string, reason?:string|null}  $data
     */
    public function extend(User $actor, ApplicationTestAssignment $assignment, array $data): ApplicationTestAssignment
    {
        return DB::transaction(function () use ($actor, $assignment, $data): ApplicationTestAssignment {
            $locked = ApplicationTestAssignment::query()
                ->with(['testAttempt', 'jobApplication.applicationStatus', 'deadlineChanges.changedBy'])
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            if ($locked->testAttempt?->submitted_at !== null) {
                throw new ConflictHttpException('A submitted test assignment can no longer be extended.');
            }

            $status = $locked->jobApplication->applicationStatus?->slug;
            if (in_array($status, self::TERMINAL_APPLICATION_STATUSES, true)) {
                throw new ConflictHttpException('A test assignment for a final application can no longer be extended.');
            }

            $newDeadline = CarbonImmutable::parse($data['deadline_at'])->utc();
            if ($newDeadline->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'deadline_at' => ['The deadline must be a future date and time.'],
                ]);
            }

            $previousDeadline = $locked->deadline_at?->toImmutable()->utc();
            if ($previousDeadline !== null && $newDeadline->lessThanOrEqualTo($previousDeadline)) {
                throw ValidationException::withMessages([
                    'deadline_at' => ['The new deadline must be later than the current deadline.'],
                ]);
            }

            $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
            $reason = $reason === '' ? null : $reason;

            $change = $locked->deadlineChanges()->create([
                'previous_deadline_at' => $previousDeadline,
                'new_deadline_at' => $newDeadline,
                'changed_by_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            $locked->forceFill(['deadline_at' => $newDeadline])->save();

            $this->auditLogService->record(
                $previousDeadline === null ? 'test_assignment.deadline_set' : 'test_assignment.deadline_extended',
                $actor,
                ApplicationTestAssignment::class,
                $locked->id,
                ['deadline_at' => $previousDeadline?->toISOString()],
                ['deadline_at' => $newDeadline->toISOString()],
                [
                    'assignment_id' => $locked->id,
                    'application_id' => $locked->job_application_id,
                    'test_id' => $locked->test_id,
                    'actor_id' => $actor->id,
                    'reason_present' => $reason !== null,
                    'deadline_change_id' => $change->id,
                ],
            );

            DB::afterCommit(fn (): array => event(new TestAssignmentDeadlineExtended($locked->id)));

            return $locked->refresh()->load([
                'test',
                'assignedBy',
                'testAttempt',
                'jobApplication.applicationStatus',
                'deadlineChanges.changedBy',
            ]);
        });
    }

    /** @return Collection<int, ApplicationTestAssignmentDeadlineChange> */
    public function history(ApplicationTestAssignment $assignment): Collection
    {
        return $assignment->deadlineChanges()
            ->with('changedBy')
            ->oldest()
            ->get();
    }
}
