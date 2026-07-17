<?php

namespace App\Services;

use App\Enums\TestAttemptGradingStatus;
use App\Enums\UserRole;
use App\Events\TestAssigned;
use App\Events\TestEvaluated;
use App\Events\TestSubmitted;
use App\Exceptions\TestScorePolicyException;
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
        private readonly TestAttemptTimingService $testAttemptTimingService,
        private readonly TestScorePolicyService $testScorePolicyService,
        private readonly TestRetakeService $testRetakeService,
        private readonly CompanyRecruitmentAccessService $companyAccessService,
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
        return DB::transaction(function () use ($actor, $data): Test {
            $companyId = $actor->role === UserRole::ADMIN
                ? (int) ($data['company_id'] ?? 0)
                : (int) ($actor->employerProfile?->company_id ?? 0);

            $company = Company::query()->findOrFail($companyId);
            unset($data['company_id'], $data['max_score']);

            $test = new Test;
            $test->fill($data);
            $test->forceFill(['max_score' => '0.00']);
            $test->company()->associate($company);
            $test->save();
            $this->testScorePolicyService->validatePassingScore($test);

            return $test->load(['company', 'questions.options']);
        });
    }

    public function getCatalogTest(Test $test): Test
    {
        return $test->load(['company', 'questions.options']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCatalogTest(User $actor, Test $test, array $data): Test
    {
        DB::transaction(function () use ($actor, $test, $data): void {
            $locked = Test::query()->lockForUpdate()->findOrFail($test->id);
            $this->ensureTestIsMutable($locked);
            unset($data['company_id'], $data['max_score']);
            $this->testScorePolicyService->synchronizeMaxScore($locked);
            if (array_key_exists('passing_score', $data)) {
                $this->testScorePolicyService->validatePassingScore($locked, $data['passing_score'], useProvided: true);
            }
            $previousPassing = $locked->passing_score;
            $locked->update($data);

            if (array_key_exists('passing_score', $data) && $previousPassing !== $locked->passing_score) {
                $this->auditLogService->record(
                    'test.passing_score_updated',
                    $actor,
                    Test::class,
                    $locked->id,
                    ['passing_score' => $previousPassing],
                    ['passing_score' => $locked->passing_score],
                    ['test_id' => $locked->id, 'max_score' => $locked->max_score, 'actor_id' => $actor->id],
                );
            }
        });

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

            $test = Test::query()->lockForUpdate()->findOrFail($testId);

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

            $this->testScorePolicyService->assertAssignable($test);

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
        $paginator = ApplicationTestAssignment::query()
            ->with([
                'test' => fn ($query) => $query
                    ->select(['id', 'title', 'description', 'instructions', 'duration_minutes'])
                    ->withCount('questions'),
                'testAttempt' => fn ($query) => $query->select([
                    'id',
                    'application_test_assignment_id',
                    'started_at',
                    'effective_deadline_at',
                    'submitted_at',
                    'grading_status',
                ]),
            ])
            ->whereHas('jobApplication.jobSeekerProfile', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->latest()
            ->paginate($perPage);

        $assignments = $paginator->getCollection();
        $rootIds = $assignments->map(fn (ApplicationTestAssignment $assignment): int => $assignment->seriesRootId())->unique()->values();
        $series = ApplicationTestAssignment::query()
            ->select(['id', 'series_root_assignment_id', 'attempt_number'])
            ->where(function ($query) use ($rootIds): void {
                $query->whereIn('id', $rootIds)->orWhereIn('series_root_assignment_id', $rootIds);
            })
            ->get()
            ->groupBy(fn (ApplicationTestAssignment $assignment): int => $assignment->seriesRootId());

        $assignments->each(function (ApplicationTestAssignment $assignment) use ($series): void {
            $items = $series->get($assignment->seriesRootId(), collect());
            $latestId = $items->sortByDesc('attempt_number')->first()?->id;
            $assignment->setAttribute('candidate_series_count', $items->count());
            $assignment->setAttribute('candidate_is_latest', $assignment->id === $latestId);
        });

        return $paginator;
    }

    public function startAttempt(User $actor, ApplicationTestAssignment $assignment): TestAttempt
    {
        return DB::transaction(function () use ($actor, $assignment): TestAttempt {
            $lockedAssignment = ApplicationTestAssignment::query()
                ->with(['test', 'testAttempt.applicationTestAssignment.test', 'jobApplication.applicationStatus'])
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $this->companyAccessService->assertRecruitmentAvailable($lockedAssignment);
            $this->testRetakeService->assertLatestCanStart($lockedAssignment);

            if ($lockedAssignment->testAttempt instanceof TestAttempt) {
                return $this->loadAttempt($lockedAssignment->testAttempt, candidateSafe: true);
            }

            $this->testAssignmentDeadlineService->assertCanStart($lockedAssignment);

            $attempt = TestAttempt::create([
                'application_test_assignment_id' => $lockedAssignment->id,
                'started_at' => now(),
            ]);

            $effectiveDeadline = $this->testAttemptTimingService->snapshot($attempt);
            $durationDeadline = $this->testAttemptTimingService->durationDeadline($attempt);

            $this->auditLogService->record(
                'test_attempt.started',
                $actor,
                TestAttempt::class,
                $attempt->id,
                null,
                ['started_at' => $attempt->started_at?->toISOString(), 'effective_deadline_at' => $effectiveDeadline->toISOString()],
                [
                    'attempt_id' => $attempt->id,
                    'assignment_id' => $lockedAssignment->id,
                    'test_id' => $lockedAssignment->test_id,
                    'started_at' => $attempt->started_at?->toISOString(),
                    'duration_minutes' => $lockedAssignment->test->duration_minutes,
                    'duration_deadline_at' => $durationDeadline->toISOString(),
                    'assignment_deadline_at' => $lockedAssignment->deadline_at?->toISOString(),
                    'effective_deadline_at' => $effectiveDeadline->toISOString(),
                    'actor_id' => $actor->id,
                ],
            );

            return $this->loadAttempt($attempt, candidateSafe: true);
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
                    'testAttempt.applicationTestAssignment.test',
                    'test.questions',
                    'jobApplication.applicationStatus',
                ])
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $this->companyAccessService->assertRecruitmentAvailable($lockedAssignment);

            $attempt = $lockedAssignment->testAttempt;

            if (! $attempt instanceof TestAttempt) {
                throw ValidationException::withMessages([
                    'assignment_id' => ['You must start the test before submitting it.'],
                ]);
            }

            if ($attempt->submitted_at !== null) {
                throw new ConflictHttpException('This test attempt has already been submitted and can no longer be modified.');
            }

            $lockedTest = Test::query()->lockForUpdate()->findOrFail($lockedAssignment->test_id);
            $this->testScorePolicyService->assertScoreConfigurationValid($lockedTest, requireScoreable: true);
            $lockedAssignment->setRelation('test', $lockedTest->load('questions'));

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

            return $this->loadAttempt($attempt, candidateSafe: true);
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
            throw new TestScorePolicyException(
                'This test score configuration can no longer be modified because the test has already been assigned.',
                'TEST_SCORE_CONFIGURATION_IMMUTABLE',
                409,
            );
        }
    }

    private function loadAttempt(TestAttempt $attempt, bool $candidateSafe = false): TestAttempt
    {
        return $attempt->load($this->attemptRelations($candidateSafe));
    }

    /**
     * @return array<int, string>
     */
    private function assignmentRelations(bool $includeApplicationContext = false, bool $candidateSafe = false): array
    {
        $relations = [
            'test',
            'testAttempt',
            'testAttempt.testAnswers.question',
            'testAttempt.testAnswers.selectedOptions',
        ];

        if (! $candidateSafe) {
            $relations[] = 'assignedBy';
            $relations[] = 'testAttempt.evaluatedBy';
            $relations[] = 'testAttempt.testAnswers.grading';
            $relations[] = 'deadlineChanges.changedBy';
            $relations[] = 'retakeGrantedBy';
            $relations[] = 'seriesRoot';
            $relations[] = 'previousAssignment';
            $relations[] = 'nextAssignment';
        }

        if ($includeApplicationContext) {
            $relations[] = 'jobApplication.jobPosting.company';
            $relations[] = 'jobApplication.jobPosting.skills';
            $relations[] = 'jobApplication.selectedCvFile';
            $relations[] = 'jobApplication.applicationStatus';
            $relations[] = 'jobApplication.statusHistory.fromStatus';
            $relations[] = 'jobApplication.statusHistory.toStatus';

            if (! $candidateSafe) {
                $relations[] = 'jobApplication.jobSeekerProfile.user';
                $relations[] = 'jobApplication.jobSeekerProfile.skills';
                $relations[] = 'jobApplication.statusHistory.changedBy';
            }
        }

        return $relations;
    }

    /**
     * @return array<int, string>
     */
    private function attemptRelations(bool $candidateSafe = false): array
    {
        if ($candidateSafe) {
            return [
                'testAnswers.question',
                'testAnswers.selectedOptions',
                'applicationTestAssignment.test',
            ];
        }

        $relations = [
            'testAnswers.question',
            'testAnswers.selectedOptions',
            'applicationTestAssignment.test',
            'applicationTestAssignment.jobApplication.jobPosting.company',
            'applicationTestAssignment.jobApplication.jobPosting.skills',
            'applicationTestAssignment.jobApplication.selectedCvFile',
            'applicationTestAssignment.jobApplication.applicationStatus',
            'applicationTestAssignment.jobApplication.statusHistory.fromStatus',
            'applicationTestAssignment.jobApplication.statusHistory.toStatus',
        ];

        $relations[] = 'evaluatedBy';
        $relations[] = 'testAnswers.grading';
        $relations[] = 'applicationTestAssignment.assignedBy';
        $relations[] = 'applicationTestAssignment.jobApplication.jobSeekerProfile.user';
        $relations[] = 'applicationTestAssignment.jobApplication.jobSeekerProfile.skills';
        $relations[] = 'applicationTestAssignment.jobApplication.statusHistory.changedBy';

        return $relations;
    }
}
