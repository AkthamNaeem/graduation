<?php

namespace App\Services;

use App\Enums\TestAttemptGradingStatus;
use App\Enums\UserRole;
use App\Events\TestAssigned;
use App\Events\TestEvaluated;
use App\Events\TestSubmitted;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TestService
{
    private const STATUS_TEST_COMPLETED = 'test_completed';

    private const STATUS_TEST_PENDING = 'test_pending';

    public function __construct(
        private readonly ApplicationWorkflowService $applicationWorkflowService,
        private readonly AuditLogService $auditLogService,
        private readonly TestAnswerService $testAnswerService,
        private readonly TestGradingService $testGradingService,
        private readonly TestAssignmentDeadlineService $testAssignmentDeadlineService,
        private readonly TestRetakeService $testRetakeService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Test>
     */
    public function getCatalogTests(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Test::query()
            ->with(['company', 'questions.options'])
            ->when($user->role === UserRole::EMPLOYER, function ($query) use ($user): void {
                $query->where('company_id', $user->employerProfile?->company_id);
            })
            ->when($user->role === UserRole::JOB_SEEKER, fn ($query) => $query->where('is_active', true))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCatalogTest(User $actor, array $data): Test
    {
        $companyId = $actor->role === UserRole::ADMIN
            ? (int) ($data['company_id'] ?? 0)
            : (int) ($actor->employerProfile?->company_id ?? 0);

        $company = Company::query()->findOrFail($companyId);
        unset($data['company_id']);

        $test = new Test;
        $test->fill($data);
        $test->company()->associate($company);
        $test->save();

        return $test->load(['company', 'questions.options']);
    }

    public function getCatalogTest(Test $test): Test
    {
        return $test->load(['company', 'questions.options']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCatalogTest(Test $test, array $data): Test
    {
        $this->ensureTestIsMutable($test);
        unset($data['company_id']);
        $test->update($data);

        return $test->refresh()->load(['company', 'questions.options']);
    }

    public function deleteCatalogTest(Test $test): void
    {
        $this->ensureTestIsMutable($test);
        $test->delete();
    }

    public function assignTest(User $actor, JobApplication $application, int $testId, ?string $note, ?string $deadlineAt = null, int $maxAttempts = 1): ApplicationTestAssignment
    {
        return DB::transaction(function () use ($actor, $application, $testId, $note, $deadlineAt, $maxAttempts): ApplicationTestAssignment {
            $jobApplication = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($application->id);

            $test = Test::query()->findOrFail($testId);

            if ($test->company_id !== $jobApplication->jobPosting->company_id) {
                throw ValidationException::withMessages([
                    'test_id' => ['The selected test must belong to the same company as the application.'],
                ]);
            }

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

            $deadline = $this->testAssignmentDeadlineService->normalizeInitialDeadline($deadlineAt);

            $assignment = ApplicationTestAssignment::create([
                'attempt_number' => 1,
                'max_attempts' => $maxAttempts,
                'job_application_id' => $jobApplication->id,
                'test_id' => $test->id,
                'assigned_by_user_id' => $actor->id,
                'note' => $note,
                'assigned_at' => now(),
                'deadline_at' => $deadline,
            ]);

            $this->auditLogService->record(
                'test.assigned',
                $actor,
                ApplicationTestAssignment::class,
                $assignment->id,
                null,
                $assignment->only(['job_application_id', 'test_id', 'assigned_by_user_id', 'attempt_number', 'max_attempts', 'assigned_at']),
                ['note' => $note],
            );

            if ($deadline !== null) {
                $this->auditLogService->record(
                    'test_assignment.deadline_set',
                    $actor,
                    ApplicationTestAssignment::class,
                    $assignment->id,
                    ['deadline_at' => null],
                    ['deadline_at' => $deadline->toISOString()],
                    [
                        'assignment_id' => $assignment->id,
                        'application_id' => $assignment->job_application_id,
                        'test_id' => $assignment->test_id,
                        'actor_id' => $actor->id,
                        'reason_present' => false,
                    ],
                );
            }

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
                ->with(['testAttempt', 'jobApplication.applicationStatus'])
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $this->testRetakeService->assertLatestCanStart($lockedAssignment);
            $this->testAssignmentDeadlineService->assertCanStart($lockedAssignment);

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
    public function submitAttempt(User $actor, ApplicationTestAssignment $assignment, ?array $answers = null): TestAttempt
    {
        return DB::transaction(function () use ($actor, $assignment, $answers): TestAttempt {
            $lockedAssignment = ApplicationTestAssignment::query()
                ->with([
                    'testAttempt',
                    'test.questions',
                    'jobApplication.applicationStatus',
                ])
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $attempt = $lockedAssignment->testAttempt;

            if (! $attempt instanceof TestAttempt) {
                throw ValidationException::withMessages([
                    'assignment_id' => ['You must start the test before submitting it.'],
                ]);
            }

            if ($attempt->submitted_at !== null) {
                throw new ConflictHttpException('This test attempt has already been submitted and can no longer be modified.');
            }

            $this->testAssignmentDeadlineService->assertCanSubmit($lockedAssignment);

            if ($answers !== null) {
                $this->testAnswerService->importLegacyPayload($attempt, $answers);
            }

            $this->testAnswerService->validateRequiredAnswers($attempt);

            $attempt->forceFill(['submitted_at' => now()])->save();
            $this->testGradingService->gradeSubmittedAttempt($attempt);

            $this->auditLogService->record(
                'test_attempt.auto_graded',
                $actor,
                TestAttempt::class,
                $attempt->id,
                null,
                $attempt->only([
                    'objective_score',
                    'objective_max_score',
                    'grading_status',
                    'auto_graded_at',
                ]),
                ['attempt_id' => $attempt->id],
            );

            if ($attempt->grading_status === TestAttemptGradingStatus::FULLY_GRADED) {
                $this->auditLogService->record(
                    'test_attempt.fully_graded',
                    $actor,
                    TestAttempt::class,
                    $attempt->id,
                    ['grading_status' => TestAttemptGradingStatus::PENDING->value],
                    ['grading_status' => TestAttemptGradingStatus::FULLY_GRADED->value, 'total_score' => $attempt->total_score],
                    ['attempt_id' => $attempt->id, 'manual_grading_required' => false],
                );
            }

            $jobApplication = $lockedAssignment->jobApplication;
            if ($jobApplication->applicationStatus?->slug !== self::STATUS_TEST_COMPLETED) {
                $this->applicationWorkflowService->changeStatus(
                    $actor,
                    $jobApplication,
                    self::STATUS_TEST_COMPLETED,
                    'Test attempt submitted.',
                );
            }

            DB::afterCommit(fn (): array => event(new TestSubmitted($attempt->id)));

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

    public function ensureTestIsMutable(Test $test): void
    {
        if ($test->applicationTestAssignments()->exists()) {
            throw new ConflictHttpException('This test can no longer be modified because it has already been assigned.');
        }
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
            'testAttempt.testAnswers.question',
            'testAttempt.testAnswers.selectedOptions',
            'testAttempt.testAnswers.grading',
            'deadlineChanges.changedBy',
            'retakeGrantedBy',
            'seriesRoot',
            'previousAssignment',
            'nextAssignment',
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
            'testAnswers.question',
            'testAnswers.selectedOptions',
            'testAnswers.grading',
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
