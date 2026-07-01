<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\TestAssigned;
use App\Events\TestEvaluated;
use App\Models\ApplicationTestAssignment;
use App\Models\JobApplication;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TestService
{
    private const STATUS_TEST_COMPLETED = 'test_completed';

    private const STATUS_TEST_PENDING = 'test_pending';

    public function __construct(
        private readonly ApplicationWorkflowService $applicationWorkflowService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Test>
     */
    public function getCatalogTests(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Test::query()
            ->when($user->role === UserRole::JOB_SEEKER, fn ($query) => $query->where('is_active', true))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCatalogTest(array $data): Test
    {
        return Test::query()->create($data);
    }

    public function getCatalogTest(Test $test): Test
    {
        return $test;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCatalogTest(Test $test, array $data): Test
    {
        $test->update($data);

        return $test->refresh();
    }

    public function deleteCatalogTest(Test $test): void
    {
        $test->delete();
    }

    public function assignTest(User $actor, JobApplication $application, int $testId, ?string $note): ApplicationTestAssignment
    {
        return DB::transaction(function () use ($actor, $application, $testId, $note): ApplicationTestAssignment {
            $jobApplication = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($application->id);

            $test = Test::query()->findOrFail($testId);

            if (! $test->is_active) {
                throw ValidationException::withMessages([
                    'test_id' => ['Only active tests can be assigned.'],
                ]);
            }

            $duplicateAssignmentExists = ApplicationTestAssignment::query()
                ->where('job_application_id', $jobApplication->id)
                ->where('test_id', $test->id)
                ->exists();

            if ($duplicateAssignmentExists) {
                throw ValidationException::withMessages([
                    'test_id' => ['This test has already been assigned to the application.'],
                ]);
            }

            $assignment = ApplicationTestAssignment::create([
                'job_application_id' => $jobApplication->id,
                'test_id' => $test->id,
                'assigned_by_user_id' => $actor->id,
                'note' => $note,
                'assigned_at' => now(),
            ]);

            $this->auditLogService->record(
                'test.assigned',
                $actor,
                ApplicationTestAssignment::class,
                $assignment->id,
                null,
                $assignment->only(['job_application_id', 'test_id', 'assigned_by_user_id', 'assigned_at']),
                ['note' => $note],
            );

            if ($jobApplication->applicationStatus?->slug !== self::STATUS_TEST_PENDING) {
                $this->applicationWorkflowService->changeStatus(
                    $actor,
                    $jobApplication,
                    self::STATUS_TEST_PENDING,
                    'Test assigned to candidate.',
                );
            }

            DB::afterCommit(fn (): array => event(new TestAssigned($assignment->id)));

            return $this->loadAssignment($assignment);
        });
    }

    /**
     * @return Collection<int, ApplicationTestAssignment>
     */
    public function getApplicationAssignments(JobApplication $application): Collection
    {
        return ApplicationTestAssignment::query()
            ->with($this->assignmentRelations())
            ->where('job_application_id', $application->id)
            ->latest()
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, ApplicationTestAssignment>
     */
    public function getMyAssignments(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return ApplicationTestAssignment::query()
            ->with($this->assignmentRelations(includeApplicationContext: true))
            ->whereHas('jobApplication.jobSeekerProfile', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->latest()
            ->paginate($perPage);
    }

    public function startAttempt(User $actor, ApplicationTestAssignment $assignment): TestAttempt
    {
        return DB::transaction(function () use ($assignment): TestAttempt {
            $lockedAssignment = ApplicationTestAssignment::query()
                ->with('testAttempt')
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            if ($lockedAssignment->testAttempt instanceof TestAttempt) {
                throw ValidationException::withMessages([
                    'assignment_id' => ['This test assignment has already been started.'],
                ]);
            }

            $attempt = TestAttempt::create([
                'application_test_assignment_id' => $lockedAssignment->id,
                'started_at' => now(),
            ]);

            return $this->loadAttempt($attempt);
        });
    }

    /**
     * @param  array<int|string, mixed>  $answers
     */
    public function submitAttempt(User $actor, ApplicationTestAssignment $assignment, array $answers): TestAttempt
    {
        return DB::transaction(function () use ($assignment, $answers): TestAttempt {
            $lockedAssignment = ApplicationTestAssignment::query()
                ->with('testAttempt')
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $attempt = $lockedAssignment->testAttempt;

            if (! $attempt instanceof TestAttempt) {
                throw ValidationException::withMessages([
                    'assignment_id' => ['You must start the test before submitting it.'],
                ]);
            }

            if ($attempt->submitted_at !== null) {
                throw ValidationException::withMessages([
                    'assignment_id' => ['This test attempt has already been submitted.'],
                ]);
            }

            $attempt->forceFill([
                'answers' => $answers,
                'submitted_at' => now(),
            ])->save();

            return $this->loadAttempt($attempt);
        });
    }

    public function evaluateAttempt(User $actor, TestAttempt $attempt, int|float $score, ?string $feedback): TestAttempt
    {
        return DB::transaction(function () use ($actor, $attempt, $score, $feedback): TestAttempt {
            $testAttempt = TestAttempt::query()
                ->with([
                    'applicationTestAssignment.jobApplication.applicationStatus',
                    'applicationTestAssignment.test',
                ])
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if ($testAttempt->submitted_at === null) {
                throw ValidationException::withMessages([
                    'attempt_id' => ['Only submitted attempts can be evaluated.'],
                ]);
            }

            if ($testAttempt->evaluated_at !== null) {
                throw ValidationException::withMessages([
                    'attempt_id' => ['This attempt has already been evaluated.'],
                ]);
            }

            $test = $testAttempt->applicationTestAssignment->test;

            if ((float) $score > (float) $test->max_score) {
                throw ValidationException::withMessages([
                    'score' => ['The score may not be greater than the test maximum score.'],
                ]);
            }

            $testAttempt->forceFill([
                'score' => $score,
                'feedback' => $feedback,
                'evaluated_by_user_id' => $actor->id,
                'evaluated_at' => now(),
            ])->save();

            $this->auditLogService->record(
                'test.evaluated',
                $actor,
                TestAttempt::class,
                $testAttempt->id,
                ['score' => null, 'feedback' => null, 'evaluated_by_user_id' => null, 'evaluated_at' => null],
                $testAttempt->only(['score', 'feedback', 'evaluated_by_user_id', 'evaluated_at']),
            );

            $jobApplication = $testAttempt->applicationTestAssignment->jobApplication;

            if ($jobApplication->applicationStatus?->slug !== self::STATUS_TEST_COMPLETED) {
                $this->applicationWorkflowService->changeStatus(
                    $actor,
                    $jobApplication,
                    self::STATUS_TEST_COMPLETED,
                    'Test attempt evaluated.',
                );
            }

            DB::afterCommit(fn (): array => event(new TestEvaluated($testAttempt->id)));

            return $this->loadAttempt($testAttempt);
        });
    }

    private function loadAssignment(ApplicationTestAssignment $assignment): ApplicationTestAssignment
    {
        return $assignment->load($this->assignmentRelations(includeApplicationContext: true));
    }

    private function loadAttempt(TestAttempt $attempt): TestAttempt
    {
        return $attempt->load($this->attemptRelations());
    }

    /**
     * @return array<int, string>
     */
    private function assignmentRelations(bool $includeApplicationContext = false): array
    {
        $relations = [
            'test',
            'assignedBy',
            'testAttempt.evaluatedBy',
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

    /**
     * @return array<int, string>
     */
    private function attemptRelations(): array
    {
        return [
            'evaluatedBy',
            'applicationTestAssignment.test',
            'applicationTestAssignment.assignedBy',
            'applicationTestAssignment.jobApplication.jobPosting.company',
            'applicationTestAssignment.jobApplication.jobPosting.skills',
            'applicationTestAssignment.jobApplication.jobSeekerProfile.user',
            'applicationTestAssignment.jobApplication.jobSeekerProfile.skills',
            'applicationTestAssignment.jobApplication.applicationStatus',
            'applicationTestAssignment.jobApplication.statusHistory.fromStatus',
            'applicationTestAssignment.jobApplication.statusHistory.toStatus',
            'applicationTestAssignment.jobApplication.statusHistory.changedBy',
        ];
    }
}
