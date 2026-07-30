<?php

namespace App\Services;

use App\Enums\ScreeningQuestionType;
use App\Exceptions\JobPostingOperationException;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use Illuminate\Database\Eloquent\Collection;

class ApplicationScreeningAnswerService
{
    private const NUMBER_MIN = -1000000000;

    private const NUMBER_MAX = 1000000000;

    /**
     * @param  array<int, array<string, mixed>>  $submittedAnswers
     * @return array<int, array{question: JobScreeningQuestion, answered: bool, text_value: ?string, number_value: int|float|null, boolean_value: ?bool, selected_option_ids: array<int, int>}>
     */
    public function buildPlan(JobPosting $lockedJobPosting, array $submittedAnswers): array
    {
        $questions = $this->lockedActiveQuestions($lockedJobPosting);
        $answersByQuestion = [];

        foreach ($submittedAnswers as $index => $submittedAnswer) {
            $questionId = (int) ($submittedAnswer['question_id'] ?? 0);
            if (isset($answersByQuestion[$questionId])) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_DUPLICATE_ANSWER'), 'APPLICATION_SCREENING_DUPLICATE_ANSWER',
                    "screening_answers.{$index}.question_id",
                );
            }
            $answersByQuestion[$questionId] = ['index' => $index, 'payload' => $submittedAnswer];
        }

        $questionMap = $questions->keyBy('id');
        foreach ($answersByQuestion as $questionId => $entry) {
            if (! $questionMap->has($questionId)) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_QUESTION_INVALID'), 'APPLICATION_SCREENING_QUESTION_INVALID',
                    "screening_answers.{$entry['index']}.question_id",
                );
            }
        }

        $plan = [];
        foreach ($questions as $question) {
            $entry = $answersByQuestion[$question->id] ?? null;
            if ($entry === null) {
                if ($question->is_required) {
                    $this->fail(__('domain_errors.APPLICATION_SCREENING_REQUIRED_ANSWER_MISSING'), 'APPLICATION_SCREENING_REQUIRED_ANSWER_MISSING',
                        'screening_answers',
                    );
                }
                $plan[] = $this->emptyPlanItem($question);

                continue;
            }

            $plan[] = $this->validateAnswer($question, $entry['payload'], $entry['index']);
        }

        return $plan;
    }

    /**
     * @param  array<int, array{question: JobScreeningQuestion, answered: bool, text_value: ?string, number_value: int|float|null, boolean_value: ?bool, selected_option_ids: array<int, int>}>  $plan
     */
    public function persistSnapshots(JobApplication $application, array $plan): void
    {
        foreach ($plan as $item) {
            $question = $item['question'];
            $snapshot = $application->screeningQuestionSnapshots()->create([
                'source_question_id' => $question->id,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'is_required' => $question->is_required,
                'sort_order' => $question->sort_order,
            ]);

            $snapshotOptionIds = [];
            foreach ($question->options as $option) {
                $snapshotOption = $snapshot->options()->create([
                    'source_option_id' => $option->id,
                    'option_text' => $option->option_text,
                    'sort_order' => $option->sort_order,
                ]);
                $snapshotOptionIds[$option->id] = $snapshotOption->id;
            }

            if (! $item['answered']) {
                continue;
            }

            $answer = $snapshot->answer()->create([
                'job_application_id' => $application->id,
                'text_value' => $item['text_value'],
                'number_value' => $item['number_value'],
                'boolean_value' => $item['boolean_value'],
            ]);

            foreach ($item['selected_option_ids'] as $sourceOptionId) {
                $answer->selectedOptions()->create([
                    'application_question_option_id' => $snapshotOptionIds[$sourceOptionId],
                ]);
            }
        }
    }

    /** @return Collection<int, JobScreeningQuestion> */
    private function lockedActiveQuestions(JobPosting $jobPosting): Collection
    {
        return JobScreeningQuestion::query()
            ->where('job_posting_id', $jobPosting->id)
            ->where('is_active', true)
            ->with('options')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question: JobScreeningQuestion, answered: bool, text_value: ?string, number_value: int|float|null, boolean_value: ?bool, selected_option_ids: array<int, int>}
     */
    private function validateAnswer(JobScreeningQuestion $question, array $payload, int $index): array
    {
        $type = $question->question_type;
        $hasValue = array_key_exists('value', $payload);
        $hasOptions = array_key_exists('selected_option_ids', $payload);
        $field = "screening_answers.{$index}";

        if ($type->isChoice()) {
            if ($hasValue) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.value");
            }
            if (! $hasOptions || ! is_array($payload['selected_option_ids'])) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_REQUIRED_ANSWER_MISSING'), 'APPLICATION_SCREENING_REQUIRED_ANSWER_MISSING', "{$field}.selected_option_ids");
            }

            $optionIds = array_map('intval', array_values($payload['selected_option_ids']));
            if ($optionIds === []
                || ($type === ScreeningQuestionType::SINGLE_CHOICE && count($optionIds) !== 1)) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.selected_option_ids");
            }
            if (count($optionIds) !== count(array_unique($optionIds))) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.selected_option_ids");
            }

            $validOptionIds = $question->options->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            foreach ($optionIds as $optionId) {
                if (! in_array($optionId, $validOptionIds, true)) {
                    $this->fail(__('domain_errors.APPLICATION_SCREENING_OPTION_INVALID'), 'APPLICATION_SCREENING_OPTION_INVALID', "{$field}.selected_option_ids");
                }
            }

            return [
                'question' => $question,
                'answered' => true,
                'text_value' => null,
                'number_value' => null,
                'boolean_value' => null,
                'selected_option_ids' => $optionIds,
            ];
        }

        if ($hasOptions) {
            $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.selected_option_ids");
        }
        if (! $hasValue) {
            $this->fail(__('domain_errors.APPLICATION_SCREENING_REQUIRED_ANSWER_MISSING'), 'APPLICATION_SCREENING_REQUIRED_ANSWER_MISSING', "{$field}.value");
        }

        $value = $payload['value'];
        if ($type === ScreeningQuestionType::SHORT_TEXT || $type === ScreeningQuestionType::LONG_TEXT) {
            if (! is_string($value)) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.value");
            }
            $value = trim($value);
            $max = $type === ScreeningQuestionType::SHORT_TEXT ? 1000 : 10000;
            if ($value === '' || mb_strlen($value) > $max) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.value");
            }

            return [
                'question' => $question,
                'answered' => true,
                'text_value' => $value,
                'number_value' => null,
                'boolean_value' => null,
                'selected_option_ids' => [],
            ];
        }

        if ($type === ScreeningQuestionType::NUMBER) {
            if ((! is_int($value) && ! is_float($value)) || $value < self::NUMBER_MIN || $value > self::NUMBER_MAX) {
                $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.value");
            }

            return [
                'question' => $question,
                'answered' => true,
                'text_value' => null,
                'number_value' => $value,
                'boolean_value' => null,
                'selected_option_ids' => [],
            ];
        }

        if (! is_bool($value)) {
            $this->fail(__('domain_errors.APPLICATION_SCREENING_ANSWER_TYPE_INVALID'), 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID', "{$field}.value");
        }

        return [
            'question' => $question,
            'answered' => true,
            'text_value' => null,
            'number_value' => null,
            'boolean_value' => $value,
            'selected_option_ids' => [],
        ];
    }

    /** @return array{question: JobScreeningQuestion, answered: false, text_value: null, number_value: null, boolean_value: null, selected_option_ids: array<int, int>} */
    private function emptyPlanItem(JobScreeningQuestion $question): array
    {
        return [
            'question' => $question,
            'answered' => false,
            'text_value' => null,
            'number_value' => null,
            'boolean_value' => null,
            'selected_option_ids' => [],
        ];
    }

    private function fail(string $message, string $code, string $field): never
    {
        throw new JobPostingOperationException($message, $code, 422, [$field => [$message]]);
    }
}
