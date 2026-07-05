<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\TestAssigned;
use App\Events\TestEvaluated;
use App\Events\TestSubmitted;
use App\Models\ApplicationTestAssignment;
use App\Models\JobApplication;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
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
        $companyId = $this->companyIdFor($user);

        return Test::query()
            ->when($user->role === UserRole::JOB_SEEKER, function ($query): void {
                $query->where('is_active', true)
                    ->where('visibility', Test::VISIBILITY_GLOBAL);
            })
            ->when($user->role === UserRole::EMPLOYER, function ($query) use ($companyId): void {
                $query->where(function ($subQuery) use ($companyId): void {
                    $subQuery->where('visibility', Test::VISIBILITY_GLOBAL)
                        ->orWhere('company_id', $companyId);
                });
            })
            ->latest()
            ->paginate($perPage);
    }

    public function getCatalogTest(User $user, Test $test): Test
    {
        $this->ensureCanReadTest($user, $test);

        if (in_array($user->role, [UserRole::ADMIN, UserRole::EMPLOYER], true)) {
            return $test->load('questions.options');
        }

        return $test;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCatalogTest(User $actor, array $data): Test
    {
        $payload = $this->testPayloadForActor($actor, $data);
        $test = Test::query()->create($payload);

        $this->auditLogService->record(
            'test.created',
            $actor,
            Test::class,
            $test->id,
            null,
            $test->only(array_keys($payload)),
        );

        return $test->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCatalogTest(User $actor, Test $test, array $data): Test
    {
        $this->ensureCanManageTest($actor, $test);
        $before = $test->only(array_keys($data));

        $payload = $this->testPayloadForActor($actor, $data, isUpdate: true);
        $test->update($payload);

        $this->auditLogService->record(
            'test.updated',
            $actor,
            Test::class,
            $test->id,
            $before,
            $test->only(array_keys($payload)),
        );

        return $test->refresh();
    }

    public function deleteCatalogTest(User $actor, Test $test): void
    {
        $this->ensureCanManageTest($actor, $test);

        if ($test->applicationTestAssignments()->exists()) {
            throw ValidationException::withMessages([
                'test' => ['Used tests cannot be deleted. Deactivate the test instead.'],
            ]);
        }

        $before = $test->only(['title', 'company_id', 'visibility', 'is_active']);
        $test->delete();

        $this->auditLogService->record(
            'test.deleted',
            $actor,
            Test::class,
            $test->id,
            $before,
            null,
        );
    }

    /**
     * @return Collection<int, TestQuestion>
     */
    public function listQuestions(User $actor, Test $test): Collection
    {
        $this->ensureCanManageTest($actor, $test);

        return $test->questions()->with('options')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createQuestion(User $actor, Test $test, array $data): TestQuestion
    {
        $this->ensureCanManageTest($actor, $test);
        $this->ensureTestEditable($test);

        return DB::transaction(function () use ($actor, $test, $data): TestQuestion {
            $options = $data['options'] ?? [];
            unset($data['options']);

            $question = $test->questions()->create($data);
            $this->replaceOptions($question, $options);

            $this->auditLogService->record(
                'test.question.created',
                $actor,
                TestQuestion::class,
                $question->id,
                null,
                $question->only(['test_id', 'question_text', 'question_type', 'points']),
            );

            return $question->load('options');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateQuestion(User $actor, Test $test, TestQuestion $question, array $data): TestQuestion
    {
        $this->ensureQuestionBelongsToTest($test, $question);
        $this->ensureCanManageTest($actor, $test);
        $this->ensureTestEditable($test);

        return DB::transaction(function () use ($actor, $question, $data): TestQuestion {
            $before = $question->only(['question_text', 'question_type', 'points', 'order_index', 'is_required', 'is_active']);
            $options = $data['options'] ?? null;
            unset($data['options']);

            $question->update($data);

            if (is_array($options)) {
                $this->replaceOptions($question, $options);
            }

            $this->auditLogService->record(
                'test.question.updated',
                $actor,
                TestQuestion::class,
                $question->id,
                $before,
                $question->only(['question_text', 'question_type', 'points', 'order_index', 'is_required', 'is_active']),
            );

            return $question->refresh()->load('options');
        });
    }

    public function deleteQuestion(User $actor, Test $test, TestQuestion $question): void
    {
        $this->ensureQuestionBelongsToTest($test, $question);
        $this->ensureCanManageTest($actor, $test);
        $this->ensureTestEditable($test);

        $before = $question->only(['test_id', 'question_text', 'question_type']);
        $question->delete();

        $this->auditLogService->record(
            'test.question.deleted',
            $actor,
            TestQuestion::class,
            $question->id,
            $before,
            null,
        );
    }

    /**
     * @param  array<int, array{id:int, order_index:int}>  $questions
     * @return Collection<int, TestQuestion>
     */
    public function reorderQuestions(User $actor, Test $test, array $questions): Collection
    {
        $this->ensureCanManageTest($actor, $test);
        $this->ensureTestEditable($test);

        return DB::transaction(function () use ($test, $questions): Collection {
            foreach ($questions as $item) {
                $question = $test->questions()->whereKey($item['id'])->firstOrFail();
                $question->update(['order_index' => $item['order_index']]);
            }

            return $test->questions()->with('options')->get();
        });
    }

    public function assignTest(User $actor, JobApplication $application, int $testId, ?string $note, ?string $deadlineAt = null): ApplicationTestAssignment
    {
        return DB::transaction(function () use ($actor, $application, $testId, $note, $deadlineAt): ApplicationTestAssignment {
            $jobApplication = JobApplication::query()
                ->with('applicationStatus')
                ->lockForUpdate()
                ->findOrFail($application->id);

            $test = Test::query()->with('questions.options')->findOrFail($testId);
            $this->ensureCanUseTest($actor, $test);

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

            $snapshot = $this->buildTestSnapshot($test);

            $assignment = ApplicationTestAssignment::create([
                'job_application_id' => $jobApplication->id,
                'test_id' => $test->id,
                'assigned_by_user_id' => $actor->id,
                'note' => $note,
                'status' => ApplicationTestAssignment::STATUS_ASSIGNED,
                'assigned_at' => now(),
                'deadline_at' => $deadlineAt,
                'test_snapshot' => $snapshot,
            ]);

            if ($test->locked_at === null) {
                $test->forceFill(['locked_at' => now()])->save();
            }

            $this->auditLogService->record(
                'test.assigned',
                $actor,
                ApplicationTestAssignment::class,
                $assignment->id,
                null,
                $assignment->only(['job_application_id', 'test_id', 'assigned_by_user_id', 'assigned_at', 'deadline_at', 'status']),
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

            $this->ensureAssignmentCanBeStarted($lockedAssignment);

            if ($lockedAssignment->testAttempt instanceof TestAttempt) {
                throw ValidationException::withMessages([
                    'assignment_id' => ['This test assignment has already been started.'],
                ]);
            }

            $lockedAssignment->forceFill([
                'status' => ApplicationTestAssignment::STATUS_STARTED,
                'started_at' => now(),
            ])->save();

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
        return DB::transaction(function () use ($actor, $assignment, $answers): TestAttempt {
            $lockedAssignment = ApplicationTestAssignment::query()
                ->with(['testAttempt', 'jobApplication.applicationStatus'])
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

            $this->ensureAssignmentNotExpired($lockedAssignment);
            $grading = $this->validateAndGradeAnswers($lockedAssignment->test_snapshot ?? [], $answers);

            $attemptPayload = [
                'answers' => $answers,
                'submitted_at' => now(),
            ];

            if ($grading['fully_auto_graded']) {
                $attemptPayload['score'] = $grading['score'];
                $attemptPayload['feedback'] = 'Automatically evaluated from objective questions.';
                $attemptPayload['evaluated_at'] = now();
            }

            $attempt->forceFill($attemptPayload)->save();

            $lockedAssignment->forceFill([
                'status' => $grading['fully_auto_graded']
                    ? ApplicationTestAssignment::STATUS_EVALUATED
                    : ApplicationTestAssignment::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'evaluated_at' => $grading['fully_auto_graded'] ? now() : null,
            ])->save();

            if ($grading['fully_auto_graded'] && $lockedAssignment->jobApplication->applicationStatus?->slug !== self::STATUS_TEST_COMPLETED) {
                $this->applicationWorkflowService->changeStatus(
                    $actor,
                    $lockedAssignment->jobApplication,
                    self::STATUS_TEST_COMPLETED,
                    'Test attempt automatically evaluated.',
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

            $testAttempt->applicationTestAssignment->forceFill([
                'status' => ApplicationTestAssignment::STATUS_EVALUATED,
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

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function replaceOptions(TestQuestion $question, array $options): void
    {
        $question->options()->delete();

        foreach (array_values($options) as $index => $option) {
            $question->options()->create([
                'option_text' => $option['option_text'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'order_index' => $option['order_index'] ?? $index,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTestSnapshot(Test $test): array
    {
        return [
            'id' => $test->id,
            'title' => $test->title,
            'instructions' => $test->instructions,
            'duration_minutes' => $test->duration_minutes,
            'max_score' => (float) $test->max_score,
            'passing_score' => $test->passing_score !== null ? (float) $test->passing_score : null,
            'questions' => $test->questions
                ->where('is_active', true)
                ->sortBy('order_index')
                ->map(fn (TestQuestion $question): array => [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'points' => (float) $question->points,
                    'order_index' => $question->order_index,
                    'is_required' => (bool) $question->is_required,
                    'expected_answer' => $question->expected_answer,
                    'options' => $question->options
                        ->sortBy('order_index')
                        ->map(fn ($option): array => [
                            'id' => $option->id,
                            'option_text' => $option->option_text,
                            'is_correct' => (bool) $option->is_correct,
                            'order_index' => $option->order_index,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int|string, mixed>  $answers
     * @return array{score: float, fully_auto_graded: bool}
     */
    private function validateAndGradeAnswers(array $snapshot, array $answers): array
    {
        $score = 0.0;
        $hasQuestions = false;
        $hasManualQuestions = false;

        foreach ($snapshot['questions'] ?? [] as $question) {
            $hasQuestions = true;
            $questionId = (string) $question['id'];
            $type = $question['question_type'];
            $isRequired = (bool) ($question['is_required'] ?? true);
            $answerExists = array_key_exists($questionId, $answers) || array_key_exists((int) $questionId, $answers);
            $answer = $answers[$questionId] ?? $answers[(int) $questionId] ?? null;

            if ($isRequired && (! $answerExists || $answer === null || $answer === '' || $answer === [])) {
                throw ValidationException::withMessages([
                    'answers' => ["Question {$questionId} is required."],
                ]);
            }

            if (! $answerExists || $answer === null || $answer === '' || $answer === []) {
                continue;
            }

            if ($type === TestQuestion::TYPE_SINGLE_CHOICE) {
                if (! is_int($answer) && ! is_string($answer)) {
                    throw ValidationException::withMessages(['answers' => ["Question {$questionId} expects one option id."]]);
                }

                $correctIds = collect($question['options'] ?? [])
                    ->filter(fn (array $option): bool => (bool) ($option['is_correct'] ?? false))
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
                    ->all();

                if (in_array((string) $answer, $correctIds, true)) {
                    $score += (float) $question['points'];
                }

                continue;
            }

            if ($type === TestQuestion::TYPE_MULTIPLE_CHOICE) {
                if (! is_array($answer)) {
                    throw ValidationException::withMessages(['answers' => ["Question {$questionId} expects an array of option ids."]]);
                }

                $correctIds = collect($question['options'] ?? [])
                    ->filter(fn (array $option): bool => (bool) ($option['is_correct'] ?? false))
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
                    ->sort()
                    ->values()
                    ->all();

                $answerIds = collect($answer)
                    ->map(fn ($id): string => (string) $id)
                    ->sort()
                    ->values()
                    ->all();

                if ($answerIds === $correctIds) {
                    $score += (float) $question['points'];
                }

                continue;
            }

            if (! is_string($answer)) {
                throw ValidationException::withMessages(['answers' => ["Question {$questionId} expects a text answer."]]);
            }

            $hasManualQuestions = true;
        }

        return [
            'score' => $score,
            'fully_auto_graded' => $hasQuestions && ! $hasManualQuestions,
        ];
    }

    private function ensureAssignmentCanBeStarted(ApplicationTestAssignment $assignment): void
    {
        if (in_array($assignment->status, [
            ApplicationTestAssignment::STATUS_CANCELLED,
            ApplicationTestAssignment::STATUS_EXPIRED,
            ApplicationTestAssignment::STATUS_SUBMITTED,
            ApplicationTestAssignment::STATUS_EVALUATED,
        ], true)) {
            throw ValidationException::withMessages([
                'assignment_id' => ['This test assignment cannot be started.'],
            ]);
        }

        $this->ensureAssignmentNotExpired($assignment);
    }

    private function ensureAssignmentNotExpired(ApplicationTestAssignment $assignment): void
    {
        if ($assignment->deadline_at !== null && $assignment->deadline_at->isPast()) {
            $assignment->forceFill(['status' => ApplicationTestAssignment::STATUS_EXPIRED])->save();

            throw ValidationException::withMessages([
                'deadline_at' => ['This test assignment deadline has passed.'],
            ]);
        }
    }

    private function ensureTestEditable(Test $test): void
    {
        if ($test->applicationTestAssignments()->exists()) {
            throw ValidationException::withMessages([
                'test' => ['This test has already been assigned. Create a new version before editing questions.'],
            ]);
        }
    }

    private function ensureCanReadTest(User $user, Test $test): void
    {
        if ($user->role === UserRole::ADMIN) {
            return;
        }

        if ($user->role === UserRole::EMPLOYER && ($test->visibility === Test::VISIBILITY_GLOBAL || $test->company_id === $this->companyIdFor($user))) {
            return;
        }

        if ($user->role === UserRole::JOB_SEEKER && $test->is_active && $test->visibility === Test::VISIBILITY_GLOBAL) {
            return;
        }

        abort(403);
    }

    private function ensureCanManageTest(User $user, Test $test): void
    {
        if ($user->role === UserRole::ADMIN) {
            return;
        }

        if ($user->role === UserRole::EMPLOYER && $test->company_id === $this->companyIdFor($user)) {
            return;
        }

        abort(403);
    }

    private function ensureCanUseTest(User $user, Test $test): void
    {
        if ($user->role === UserRole::ADMIN) {
            return;
        }

        if ($user->role === UserRole::EMPLOYER && ($test->visibility === Test::VISIBILITY_GLOBAL || $test->company_id === $this->companyIdFor($user))) {
            return;
        }

        abort(403);
    }

    private function ensureQuestionBelongsToTest(Test $test, TestQuestion $question): void
    {
        abort_unless($question->test_id === $test->id, 404);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function testPayloadForActor(User $actor, array $data, bool $isUpdate = false): array
    {
        if ($actor->role === UserRole::EMPLOYER) {
            unset($data['company_id'], $data['visibility']);

            $companyId = $this->companyIdFor($actor);

            if ($companyId === null) {
                throw ValidationException::withMessages([
                    'company_id' => ['Employer must belong to a company before managing tests.'],
                ]);
            }

            return array_merge($data, array_filter([
                'company_id' => $companyId,
                'created_by_user_id' => $isUpdate ? null : $actor->id,
                'visibility' => Test::VISIBILITY_COMPANY,
            ], fn ($value): bool => $value !== null));
        }

        if (! $isUpdate) {
            $data['created_by_user_id'] = $actor->id;
        }

        $data['visibility'] = $data['visibility'] ?? Test::VISIBILITY_GLOBAL;

        if ($data['visibility'] === Test::VISIBILITY_GLOBAL) {
            $data['company_id'] = null;
        }

        return $data;
    }

    private function companyIdFor(User $user): ?int
    {
        return $user->employerProfile()->value('company_id');
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
