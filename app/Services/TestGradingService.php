<?php

namespace App\Services;

use App\Enums\TestAnswerGradingType;
use App\Enums\TestAttemptGradingStatus;
use App\Enums\TestQuestionType;
use App\Models\TestAnswer;
use App\Models\TestAnswerGrading;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use Illuminate\Validation\ValidationException;

class TestGradingService
{
    public function gradeSubmittedAttempt(TestAttempt $attempt): TestAttempt
    {
        if ($attempt->submitted_at === null) {
            throw ValidationException::withMessages([
                'attempt_id' => ['Only submitted attempts can be auto-graded.'],
            ]);
        }

        $attempt->load([
            'applicationTestAssignment.test.questions.options',
            'testAnswers.selectedOptions',
            'testAnswers.grading.gradedBy',
        ]);

        $questions = $attempt->applicationTestAssignment->test->questions;
        $answers = $attempt->testAnswers->keyBy('test_question_id');
        $objectiveScore = 0.0;
        $objectiveMaxScore = 0.0;
        $manualMaxScore = 0.0;
        $hasSubjectiveQuestions = false;
        $requiresManualGrading = false;
        $gradedAt = now();

        foreach ($questions as $question) {
            $points = (float) $question->points;

            if (! $question->question_type->acceptsOptions()) {
                $hasSubjectiveQuestions = true;
                $manualMaxScore += $points;
                $requiresManualGrading = $requiresManualGrading || $answers->has($question->id);

                continue;
            }

            $objectiveMaxScore += $points;
            $answer = $answers->get($question->id);

            if (! $answer instanceof TestAnswer) {
                continue;
            }

            $isCorrect = $this->isObjectiveAnswerCorrect($question, $answer);
            $awardedPoints = $isCorrect ? $points : 0.0;
            $objectiveScore += $awardedPoints;

            TestAnswerGrading::query()->updateOrCreate(
                ['test_answer_id' => $answer->id],
                [
                    'grading_type' => TestAnswerGradingType::AUTOMATIC,
                    'is_correct' => $isCorrect,
                    'awarded_points' => $awardedPoints,
                    'max_points' => $points,
                    'explanation' => $this->explanation($question->question_type, $isCorrect),
                    'graded_by' => null,
                    'graded_at' => $gradedAt,
                ],
            );
        }

        $maxScore = $objectiveMaxScore + $manualMaxScore;
        $finalizedWithoutManualWork = $hasSubjectiveQuestions && ! $requiresManualGrading;
        $totalScore = $requiresManualGrading ? null : $objectiveScore;
        $percentage = ! $requiresManualGrading && $maxScore > 0
            ? round(($objectiveScore / $maxScore) * 100, 2)
            : null;

        $attempt->forceFill([
            'objective_score' => $objectiveScore,
            'objective_max_score' => $objectiveMaxScore,
            'manual_score' => $finalizedWithoutManualWork ? 0 : null,
            'manual_max_score' => $manualMaxScore,
            'total_score' => $totalScore,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'grading_status' => match (true) {
                $requiresManualGrading => TestAttemptGradingStatus::MANUAL_GRADING_REQUIRED,
                $finalizedWithoutManualWork => TestAttemptGradingStatus::FULLY_GRADED,
                default => TestAttemptGradingStatus::AUTO_GRADED,
            },
            'auto_graded_at' => $gradedAt,
            'manually_graded_at' => $finalizedWithoutManualWork ? $gradedAt : null,
        ])->save();

        return $this->loadResult($attempt);
    }

    public function getResult(TestAttempt $attempt): TestAttempt
    {
        if ($attempt->submitted_at === null) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                'This test attempt has not been submitted yet.',
            );
        }

        return $this->loadResult($attempt);
    }

    private function isObjectiveAnswerCorrect(TestQuestion $question, TestAnswer $answer): bool
    {
        $selectedIds = $answer->selectedOptions->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $correctIds = $question->options->where('is_correct', true)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        return $selectedIds === $correctIds;
    }

    private function explanation(TestQuestionType $type, bool $isCorrect): string
    {
        if ($type === TestQuestionType::MULTIPLE_CHOICE) {
            return $isCorrect
                ? 'The selected option set exactly matches the correct option set.'
                : 'The selected option set does not exactly match the correct option set.';
        }

        return $isCorrect
            ? 'The selected option exactly matches the correct option.'
            : 'The selected option does not match the correct option.';
    }

    private function loadResult(TestAttempt $attempt): TestAttempt
    {
        return $attempt->load([
            'applicationTestAssignment.test.questions.options',
            'applicationTestAssignment.jobApplication.jobPosting.company',
            'applicationTestAssignment.jobApplication.jobSeekerProfile',
            'testAnswers.selectedOptions',
            'testAnswers.grading.gradedBy',
        ]);
    }
}
