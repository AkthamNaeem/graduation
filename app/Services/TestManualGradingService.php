<?php

namespace App\Services;

use App\Enums\TestAnswerGradingType;
use App\Enums\TestAttemptGradingStatus;
use App\Models\TestAnswer;
use App\Models\TestAnswerGrading;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TestManualGradingService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    /** @param array{awarded_points:int|float|string, reviewer_note?:string|null} $data */
    public function upsert(User $actor, TestAttempt $attempt, TestQuestion $question, array $data): TestAttempt
    {
        return DB::transaction(function () use ($actor, $attempt, $question, $data): TestAttempt {
            $lockedAttempt = $this->lockAttempt($attempt);
            [$answer, $lockedQuestion] = $this->resolveManualAnswer($lockedAttempt, $question);
            $this->validatePoints($data['awarded_points'], $lockedQuestion);
            $existing = TestAnswerGrading::query()->where('test_answer_id', $answer->id)->lockForUpdate()->first();

            if ($existing?->grading_type === TestAnswerGradingType::AUTOMATIC) {
                throw ValidationException::withMessages([
                    'question_id' => ['Automatically graded answers cannot be manually overridden.'],
                ]);
            }

            $statusBefore = $lockedAttempt->grading_status->value;
            $beforePoints = $existing?->awarded_points;
            $note = $this->normalizeNote($data['reviewer_note'] ?? null);
            $grading = TestAnswerGrading::query()->updateOrCreate(
                ['test_answer_id' => $answer->id],
                [
                    'grading_type' => TestAnswerGradingType::MANUAL,
                    'is_correct' => null,
                    'awarded_points' => $data['awarded_points'],
                    'max_points' => $lockedQuestion->points,
                    'explanation' => $note,
                    'graded_by' => $actor->id,
                    'graded_at' => now(),
                ],
            );

            $result = $this->recalculateLocked($lockedAttempt, $actor);
            $action = $existing === null ? 'test_answer.manually_graded' : 'test_answer.manual_grading_updated';
            $this->recordAnswerAudit(
                $action,
                $actor,
                $result,
                $answer,
                $lockedQuestion,
                $beforePoints,
                $grading->awarded_points,
                $statusBefore,
                $note,
            );

            return $result;
        });
    }

    /** @param array<int, array{question_id:int, awarded_points:int|float|string, reviewer_note?:string|null}> $items */
    public function bulkUpsert(User $actor, TestAttempt $attempt, array $items): TestAttempt
    {
        return DB::transaction(function () use ($actor, $attempt, $items): TestAttempt {
            $lockedAttempt = $this->lockAttempt($attempt);
            $questionIds = array_map(fn (array $item): int => (int) $item['question_id'], $items);

            if (count($questionIds) !== count(array_unique($questionIds))) {
                throw ValidationException::withMessages(['gradings' => ['Question IDs must not be duplicated.']]);
            }

            $testId = $lockedAttempt->applicationTestAssignment()->value('test_id');
            $questions = TestQuestion::query()->whereIn('id', $questionIds)->where('test_id', $testId)->get()->keyBy('id');
            $answers = TestAnswer::query()
                ->where('test_attempt_id', $lockedAttempt->id)
                ->whereIn('test_question_id', $questionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('test_question_id');
            $resolved = [];

            foreach ($items as $item) {
                $question = $questions->get((int) $item['question_id']);
                if (! $question instanceof TestQuestion) {
                    throw ValidationException::withMessages(['gradings' => ['Every question must exist and belong to this attempt.']]);
                }
                if (! $question->question_type->requiresManualGrading()) {
                    throw ValidationException::withMessages([
                        'gradings' => ['Automatically graded answers cannot be manually overridden.'],
                    ]);
                }
                $answer = $answers->get($question->id);
                if (! $answer instanceof TestAnswer) {
                    throw ValidationException::withMessages(['gradings' => ['Every subjective question must have an answer.']]);
                }
                $this->validatePoints($item['awarded_points'], $question);
                $resolved[] = [$answer, $question, $item];
            }

            $answerIds = collect($resolved)->pluck('0.id')->all();
            $existingGradings = TestAnswerGrading::query()
                ->whereIn('test_answer_id', $answerIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('test_answer_id');

            if ($existingGradings->contains(fn (TestAnswerGrading $grading): bool => $grading->grading_type === TestAnswerGradingType::AUTOMATIC)) {
                throw ValidationException::withMessages([
                    'gradings' => ['Automatically graded answers cannot be manually overridden.'],
                ]);
            }

            $statusBefore = $lockedAttempt->grading_status->value;
            $audits = [];
            foreach ($resolved as [$answer, $question, $item]) {
                $existing = $existingGradings->get($answer->id);
                $note = $this->normalizeNote($item['reviewer_note'] ?? null);
                $grading = TestAnswerGrading::query()->updateOrCreate(
                    ['test_answer_id' => $answer->id],
                    [
                        'grading_type' => TestAnswerGradingType::MANUAL,
                        'is_correct' => null,
                        'awarded_points' => $item['awarded_points'],
                        'max_points' => $question->points,
                        'explanation' => $note,
                        'graded_by' => $actor->id,
                        'graded_at' => now(),
                    ],
                );
                $audits[] = [$existing === null ? 'test_answer.manually_graded' : 'test_answer.manual_grading_updated', $answer, $question, $existing?->awarded_points, $grading->awarded_points, $note];
            }

            $result = $this->recalculateLocked($lockedAttempt, $actor);
            foreach ($audits as [$action, $answer, $question, $before, $after, $note]) {
                $this->recordAnswerAudit($action, $actor, $result, $answer, $question, $before, $after, $statusBefore, $note);
            }

            return $result;
        });
    }

    public function delete(User $actor, TestAttempt $attempt, TestQuestion $question): TestAttempt
    {
        return DB::transaction(function () use ($actor, $attempt, $question): TestAttempt {
            $lockedAttempt = $this->lockAttempt($attempt);
            [$answer, $lockedQuestion] = $this->resolveManualAnswer($lockedAttempt, $question);
            $grading = TestAnswerGrading::query()->where('test_answer_id', $answer->id)->lockForUpdate()->firstOrFail();

            if ($grading->grading_type === TestAnswerGradingType::AUTOMATIC) {
                throw ValidationException::withMessages([
                    'question_id' => ['Automatically graded answers cannot be manually overridden.'],
                ]);
            }

            $statusBefore = $lockedAttempt->grading_status->value;
            $beforePoints = $grading->awarded_points;
            $note = $grading->explanation;
            $grading->delete();
            $result = $this->recalculateLocked($lockedAttempt, $actor);
            $this->recordAnswerAudit(
                'test_answer.manual_grading_removed',
                $actor,
                $result,
                $answer,
                $lockedQuestion,
                $beforePoints,
                null,
                $statusBefore,
                $note,
            );

            return $result;
        });
    }

    private function lockAttempt(TestAttempt $attempt): TestAttempt
    {
        $locked = TestAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
        if ($locked->submitted_at === null) {
            throw new ConflictHttpException('This test attempt has not been submitted yet.');
        }

        return $locked;
    }

    /** @return array{TestAnswer, TestQuestion} */
    private function resolveManualAnswer(TestAttempt $attempt, TestQuestion $question): array
    {
        $testId = $attempt->applicationTestAssignment()->value('test_id');
        if ($question->test_id !== $testId) {
            throw ValidationException::withMessages(['question_id' => ['The question must belong to the attempt test.']]);
        }
        if (! $question->question_type->requiresManualGrading()) {
            throw ValidationException::withMessages([
                'question_id' => ['Automatically graded answers cannot be manually overridden.'],
            ]);
        }

        $answer = TestAnswer::query()
            ->where('test_attempt_id', $attempt->id)
            ->where('test_question_id', $question->id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$answer, $question];
    }

    private function validatePoints(int|float|string $awardedPoints, TestQuestion $question): void
    {
        if ((float) $awardedPoints > (float) $question->points) {
            throw ValidationException::withMessages([
                'awarded_points' => ['Awarded points cannot exceed the question maximum.'],
            ]);
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        $trimmed = $note === null ? null : trim($note);

        return $trimmed === '' ? null : $trimmed;
    }

    private function recalculateLocked(TestAttempt $attempt, User $actor): TestAttempt
    {
        $attempt->load([
            'applicationTestAssignment.test.questions',
            'testAnswers.question',
            'testAnswers.grading',
        ]);
        $subjectiveQuestions = $attempt->applicationTestAssignment->test->questions
            ->filter(fn (TestQuestion $question): bool => $question->question_type->requiresManualGrading());
        $subjectiveQuestionIds = $subjectiveQuestions->pluck('id');
        $subjectiveAnswers = $attempt->testAnswers->whereIn('test_question_id', $subjectiveQuestionIds);
        $manualGradings = $subjectiveAnswers
            ->pluck('grading')
            ->filter(fn ($grading): bool => $grading?->grading_type === TestAnswerGradingType::MANUAL);
        $complete = $manualGradings->count() === $subjectiveAnswers->count();
        $statusBefore = $attempt->grading_status;

        $attributes = [
            'manual_max_score' => $subjectiveQuestions->sum(fn (TestQuestion $question): float => (float) $question->points),
        ];

        if ($complete) {
            $manualScore = $manualGradings->sum(fn (TestAnswerGrading $grading): float => (float) $grading->awarded_points);
            $totalScore = (float) $attempt->objective_score + $manualScore;
            $maxScore = (float) $attempt->objective_max_score + (float) $attributes['manual_max_score'];
            $attributes += [
                'manual_score' => $manualScore,
                'total_score' => $totalScore,
                'max_score' => $maxScore,
                'percentage' => $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : null,
                'grading_status' => TestAttemptGradingStatus::FULLY_GRADED,
                'manually_graded_at' => now(),
            ];
        } else {
            $attributes += [
                'manual_score' => null,
                'total_score' => null,
                'max_score' => (float) $attempt->objective_max_score + (float) $attributes['manual_max_score'],
                'percentage' => null,
                'grading_status' => TestAttemptGradingStatus::MANUAL_GRADING_REQUIRED,
                'manually_graded_at' => null,
            ];
        }

        $attempt->forceFill($attributes)->save();

        if ($statusBefore !== TestAttemptGradingStatus::FULLY_GRADED && $attempt->grading_status === TestAttemptGradingStatus::FULLY_GRADED) {
            $this->auditLogService->record(
                'test_attempt.fully_graded',
                $actor,
                TestAttempt::class,
                $attempt->id,
                ['grading_status' => $statusBefore->value],
                ['grading_status' => TestAttemptGradingStatus::FULLY_GRADED->value, 'total_score' => $attempt->total_score],
                ['attempt_id' => $attempt->id],
            );
        }

        return $attempt->load([
            'applicationTestAssignment.test.questions.options',
            'applicationTestAssignment.jobApplication.jobPosting.company',
            'applicationTestAssignment.jobApplication.jobSeekerProfile',
            'testAnswers.selectedOptions',
            'testAnswers.grading.gradedBy',
        ]);
    }

    private function recordAnswerAudit(
        string $action,
        User $actor,
        TestAttempt $attempt,
        TestAnswer $answer,
        TestQuestion $question,
        int|float|string|null $beforePoints,
        int|float|string|null $afterPoints,
        string $statusBefore,
        ?string $note,
    ): void {
        $this->auditLogService->record(
            $action,
            $actor,
            TestAnswer::class,
            $answer->id,
            ['awarded_points' => $beforePoints],
            ['awarded_points' => $afterPoints],
            [
                'attempt_id' => $attempt->id,
                'answer_id' => $answer->id,
                'question_id' => $question->id,
                'max_points' => $question->points,
                'grading_status_before' => $statusBefore,
                'grading_status_after' => $attempt->grading_status->value,
                'actor_id' => $actor->id,
                'reviewer_note_present' => $note !== null,
                'reviewer_note_length' => $note === null ? 0 : mb_strlen($note),
            ],
        );
    }
}
