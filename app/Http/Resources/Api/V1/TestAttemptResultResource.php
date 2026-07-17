<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use App\Models\TestAnswer;
use App\Models\TestQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin \App\Models\TestAttempt */
class TestAttemptResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->role($request);
        $detailed = $role === UserRole::EMPLOYER || $role === UserRole::ADMIN;
        $assignment = $this->applicationTestAssignment;

        return [
            'attempt_id' => $this->id,
            'attempt_number' => $assignment?->attempt_number,
            'deadline_at' => $assignment?->deadline_at?->toISOString(),
            'is_expired' => $assignment?->isExpired() ?? false,
            'grading_status' => $this->grading_status?->value,
            'objective_score' => $this->objective_score,
            'objective_max_score' => $this->objective_max_score,
            'manual_score' => $this->manual_score,
            'manual_max_score' => $this->manual_max_score,
            'total_score' => $this->total_score,
            'max_score' => $this->max_score,
            'percentage' => $this->percentage,
            'is_passing_score_met' => $this->passingScoreMet(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'auto_graded_at' => $this->auto_graded_at?->toISOString(),
            'manually_graded_at' => $this->manually_graded_at?->toISOString(),
            'manual_grading_progress' => $this->when($detailed, fn (): array => $this->manualGradingProgress()),
            'breakdown' => $this->when($detailed, fn (): array => $this->breakdown()),
        ];
    }

    /** @return array{total:int, graded:int, remaining:int, complete:bool} */
    private function manualGradingProgress(): array
    {
        $subjectiveQuestions = $this->applicationTestAssignment->test->questions
            ->filter(fn (TestQuestion $question): bool => $question->question_type->requiresManualGrading());
        $subjectiveIds = $subjectiveQuestions->pluck('id');
        $answers = $this->testAnswers->whereIn('test_question_id', $subjectiveIds);
        $graded = $answers->filter(
            fn (TestAnswer $answer): bool => $answer->grading?->grading_type?->value === 'manual',
        )->count();
        $remaining = $answers->count() - $graded;

        return [
            'total' => $subjectiveQuestions->count(),
            'graded' => $graded,
            'remaining' => $remaining,
            'complete' => $remaining === 0,
        ];
    }

    private function role(Request $request): ?UserRole
    {
        $token = $request->bearerToken();
        $user = $token ? PersonalAccessToken::findToken($token)?->tokenable : null;

        return $user?->role;
    }

    /** @return array<int, array<string, mixed>> */
    private function breakdown(): array
    {
        $answers = $this->testAnswers->keyBy('test_question_id');

        return $this->applicationTestAssignment->test->questions
            ->map(function (TestQuestion $question) use ($answers): array {
                $answer = $answers->get($question->id);
                $objective = $question->question_type->acceptsOptions();
                $grading = $answer instanceof TestAnswer ? $answer->grading : null;
                $manualGrading = ! $objective && $grading?->grading_type?->value === 'manual' ? $grading : null;

                $item = [
                    'question_id' => $question->id,
                    'question_type' => $question->question_type->value,
                    'question_text' => $question->question_text,
                    'answered' => $answer instanceof TestAnswer,
                    'max_points' => $question->points,
                    'awarded_points' => $objective
                        ? ($grading?->awarded_points ?? '0.00')
                        : ($answer instanceof TestAnswer ? $manualGrading?->awarded_points : '0.00'),
                    'grading_type' => $grading?->grading_type?->value,
                    'requires_manual_grading' => ! $objective && $answer instanceof TestAnswer && $manualGrading === null,
                ];

                if ($objective) {
                    $item += [
                        'is_correct' => $grading?->is_correct ?? false,
                        'selected_options' => $answer instanceof TestAnswer
                            ? $answer->selectedOptions->map(fn ($option): array => [
                                'id' => $option->id,
                                'option_text' => $option->option_text,
                            ])->values()->all()
                            : [],
                        'correct_options' => $question->options->where('is_correct', true)->map(fn ($option): array => [
                            'id' => $option->id,
                            'option_text' => $option->option_text,
                        ])->values()->all(),
                        'explanation' => $grading?->explanation ?? 'The optional question was not answered.',
                    ];
                } else {
                    $item += [
                        'answer_text' => $answer?->answer_text,
                        'file' => $answer?->file_path === null ? null : [
                            'original_name' => $answer->file_original_name,
                            'mime_type' => $answer->file_mime_type,
                            'size' => $answer->file_size,
                            'download_available' => true,
                        ],
                        'reviewer_note' => $manualGrading?->explanation,
                        'graded_at' => $manualGrading?->graded_at?->toISOString(),
                        'graded_by' => $manualGrading?->gradedBy === null ? null : [
                            'id' => $manualGrading->gradedBy->id,
                            'name' => $manualGrading->gradedBy->name,
                        ],
                    ];
                }

                return $item;
            })
            ->values()
            ->all();
    }
}
