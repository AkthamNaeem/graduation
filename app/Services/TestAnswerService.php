<?php

namespace App\Services;

use App\Enums\TestQuestionType;
use App\Models\TestAnswer;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TestAnswerService
{
    private const FILE_DISK = 'local';

    public function __construct(
        private readonly TestAssignmentDeadlineService $testAssignmentDeadlineService,
        private readonly CompanyRecruitmentAccessService $companyAccessService,
    ) {}

    /** @return Collection<int, TestAnswer> */
    public function listAnswers(TestAttempt $attempt): Collection
    {
        return $attempt->testAnswers()->with(['question', 'selectedOptions'])->get();
    }

    public function upsertAnswer(
        TestAttempt $attempt,
        TestQuestion $question,
        array $payload,
        ?UploadedFile $file = null,
    ): TestAnswer {
        $this->companyAccessService->assertRecruitmentAvailable($attempt);
        $this->ensureAttemptMutable($attempt);
        $this->ensureQuestionBelongsToAttempt($attempt, $question);
        $normalized = $this->validatePayload($question, $payload, $file);

        $newPath = null;
        $oldFile = TestAnswer::query()
            ->where('test_attempt_id', $attempt->id)
            ->where('test_question_id', $question->id)
            ->first();

        if ($file !== null) {
            $extension = strtolower($file->getClientOriginalExtension());
            $newPath = $file->storeAs(
                "test-answers/{$attempt->id}",
                Str::uuid().'.'.$extension,
                self::FILE_DISK,
            );

            if (! is_string($newPath)) {
                throw ValidationException::withMessages(['answer_file' => ['The answer file could not be stored.']]);
            }
        }

        try {
            $answer = DB::transaction(function () use ($attempt, $question, $normalized, $file, $newPath): TestAnswer {
                $this->ensureAttemptMutable($attempt);
                $attributes = ['answer_text' => $normalized['answer_text']];

                if ($file !== null) {
                    $attributes += [
                        'file_path' => $newPath,
                        'file_disk' => self::FILE_DISK,
                        'file_original_name' => basename($file->getClientOriginalName()),
                        'file_mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ];
                }

                $answer = TestAnswer::query()->updateOrCreate([
                    'test_attempt_id' => $attempt->id,
                    'test_question_id' => $question->id,
                ], $attributes);
                $answer->selectedOptions()->sync($normalized['selected_option_ids']);

                return $this->loadAnswer($answer);
            });
        } catch (\Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk(self::FILE_DISK)->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath !== null && $oldFile?->file_path !== null && $oldFile->file_path !== $newPath) {
            Storage::disk($oldFile->file_disk ?: self::FILE_DISK)->delete($oldFile->file_path);
        }

        return $answer;
    }

    /** @return Collection<int, TestAnswer> */
    public function bulkUpsert(TestAttempt $attempt, array $answers): Collection
    {
        $this->companyAccessService->assertRecruitmentAvailable($attempt);
        $this->ensureAttemptMutable($attempt);

        $questionIds = array_map(fn (array $answer): int => (int) $answer['question_id'], $answers);
        if (count($questionIds) !== count(array_unique($questionIds))) {
            throw ValidationException::withMessages(['answers' => ['Question IDs must not be duplicated.']]);
        }

        $questions = TestQuestion::query()->whereIn('id', $questionIds)->get()->keyBy('id');
        $normalized = [];

        foreach ($answers as $item) {
            $question = $questions->get((int) $item['question_id']);
            if (! $question instanceof TestQuestion) {
                throw ValidationException::withMessages(['answers' => ['Every question must exist and belong to this test attempt.']]);
            }
            $this->ensureQuestionBelongsToAttempt($attempt, $question);
            $normalized[] = [$question, $this->validatePayload($question, $item)];
        }

        DB::transaction(function () use ($attempt, $normalized): void {
            $this->ensureAttemptMutable($attempt);
            foreach ($normalized as [$question, $payload]) {
                $answer = TestAnswer::query()->updateOrCreate([
                    'test_attempt_id' => $attempt->id,
                    'test_question_id' => $question->id,
                ], ['answer_text' => $payload['answer_text']]);
                $answer->selectedOptions()->sync($payload['selected_option_ids']);
            }
        });

        return $this->listAnswers($attempt);
    }

    public function deleteAnswer(TestAttempt $attempt, TestQuestion $question): void
    {
        $this->companyAccessService->assertRecruitmentAvailable($attempt);
        $this->ensureAttemptMutable($attempt);
        $this->ensureQuestionBelongsToAttempt($attempt, $question);

        $answer = TestAnswer::query()
            ->where('test_attempt_id', $attempt->id)
            ->where('test_question_id', $question->id)
            ->firstOrFail();
        $disk = $answer->file_disk;
        $path = $answer->file_path;

        DB::transaction(function () use ($attempt, $answer): void {
            $this->ensureAttemptMutable($attempt);
            $answer->delete();
        });

        if ($path !== null) {
            Storage::disk($disk ?: self::FILE_DISK)->delete($path);
        }
    }

    public function fileAnswer(TestAttempt $attempt, TestQuestion $question): TestAnswer
    {
        $this->ensureQuestionBelongsToAttempt($attempt, $question);

        $answer = TestAnswer::query()
            ->where('test_attempt_id', $attempt->id)
            ->where('test_question_id', $question->id)
            ->whereNotNull('file_path')
            ->firstOrFail();

        if (! Storage::disk($answer->file_disk ?: self::FILE_DISK)->exists($answer->file_path)) {
            abort(404);
        }

        return $answer;
    }

    public function ensureAttemptMutable(TestAttempt $attempt): void
    {
        $attempt->refresh();
        if ($attempt->submitted_at !== null) {
            throw new ConflictHttpException('This test attempt has already been submitted and can no longer be modified.');
        }
        if ($attempt->started_at === null) {
            throw new ConflictHttpException('This test attempt has not been started yet.');
        }
        $this->testAssignmentDeadlineService->assertCanMutateAnswers($attempt);
    }

    public function ensureQuestionBelongsToAttempt(TestAttempt $attempt, TestQuestion $question): void
    {
        $testId = $attempt->applicationTestAssignment()->value('test_id');
        if ($question->test_id !== $testId) {
            throw ValidationException::withMessages(['question_id' => ['The question must belong to the assigned test.']]);
        }
    }

    public function validateRequiredAnswers(TestAttempt $attempt): void
    {
        $attempt->loadMissing([
            'applicationTestAssignment.test.questions',
            'testAnswers.selectedOptions',
        ]);
        $answers = $attempt->testAnswers->keyBy('test_question_id');
        $missing = $attempt->applicationTestAssignment->test->questions
            ->where('is_required', true)
            ->filter(function (TestQuestion $question) use ($answers): bool {
                $answer = $answers->get($question->id);

                return ! $answer instanceof TestAnswer || ! $this->isComplete($question, $answer);
            })
            ->pluck('id')
            ->values()
            ->all();

        if ($missing !== []) {
            throw ValidationException::withMessages(['unanswered_question_ids' => $missing]);
        }

        $questions = $attempt->applicationTestAssignment->test->questions->keyBy('id');
        $invalid = $attempt->testAnswers
            ->filter(function (TestAnswer $answer) use ($questions): bool {
                $question = $questions->get($answer->test_question_id);

                return ! $question instanceof TestQuestion || ! $this->isComplete($question, $answer);
            })
            ->pluck('test_question_id')
            ->values()
            ->all();

        if ($invalid !== []) {
            throw ValidationException::withMessages(['invalid_answer_question_ids' => $invalid]);
        }
    }

    public function importLegacyPayload(TestAttempt $attempt, array $answers): void
    {
        if ($answers === []) {
            return;
        }

        $isStructured = array_is_list($answers)
            && collect($answers)->every(fn ($answer) => is_array($answer) && isset($answer['question_id']));

        if ($isStructured) {
            $this->bulkUpsert($attempt, $answers);

            return;
        }

        $questionCount = $attempt->applicationTestAssignment->test()->withCount('questions')->firstOrFail()->questions_count;
        if ($questionCount > 0) {
            throw ValidationException::withMessages([
                'answers' => ['Legacy answer maps cannot be matched safely. Save normalized answers before submitting.'],
            ]);
        }
    }

    private function validatePayload(TestQuestion $question, array $payload, ?UploadedFile $file = null): array
    {
        $type = $question->question_type;
        $hasText = array_key_exists('answer_text', $payload);
        $hasOptions = array_key_exists('selected_option_ids', $payload);

        if ($type->acceptsOptions()) {
            if ($hasText || $file !== null || ! $hasOptions) {
                throw ValidationException::withMessages(['answer' => ['Choice questions accept selected_option_ids only.']]);
            }
            $optionIds = array_map('intval', $payload['selected_option_ids']);
            if ($optionIds === [] || count($optionIds) !== count(array_unique($optionIds))) {
                throw ValidationException::withMessages(['selected_option_ids' => ['Select valid, non-duplicated options.']]);
            }
            if (in_array($type, [TestQuestionType::SINGLE_CHOICE, TestQuestionType::TRUE_FALSE], true) && count($optionIds) !== 1) {
                throw ValidationException::withMessages(['selected_option_ids' => ['Exactly one option must be selected.']]);
            }
            if ($question->options()->whereIn('id', $optionIds)->count() !== count($optionIds)) {
                throw ValidationException::withMessages(['selected_option_ids' => ['Every selected option must belong to the question.']]);
            }

            return ['answer_text' => null, 'selected_option_ids' => $optionIds];
        }

        if (in_array($type, [TestQuestionType::SHORT_TEXT, TestQuestionType::LONG_TEXT], true)) {
            if (! $hasText || $hasOptions || $file !== null) {
                throw ValidationException::withMessages(['answer' => ['Text questions accept answer_text only.']]);
            }
            $text = trim((string) $payload['answer_text']);
            $max = $type === TestQuestionType::SHORT_TEXT ? 1000 : 10000;
            if ($text === '' || mb_strlen($text) > $max) {
                throw ValidationException::withMessages(['answer_text' => ["The answer text must contain between 1 and {$max} characters."]]);
            }

            return ['answer_text' => $text, 'selected_option_ids' => []];
        }

        if ($type === TestQuestionType::FILE_UPLOAD) {
            if ($file === null || $hasText || $hasOptions) {
                throw ValidationException::withMessages(['answer_file' => ['File questions accept one answer_file only.']]);
            }

            return ['answer_text' => null, 'selected_option_ids' => []];
        }

        throw ValidationException::withMessages(['question_type' => ['The question type is not supported.']]);
    }

    private function isComplete(TestQuestion $question, TestAnswer $answer): bool
    {
        if ($question->question_type->acceptsOptions()) {
            $optionCount = $answer->selectedOptions->count();
            $expectedCountIsValid = $question->question_type === TestQuestionType::MULTIPLE_CHOICE
                ? $optionCount >= 1
                : $optionCount === 1;

            return $answer->answer_text === null
                && $answer->file_path === null
                && $expectedCountIsValid
                && $answer->selectedOptions->every(
                    fn ($option): bool => $option->test_question_id === $question->id,
                );
        }
        if (in_array($question->question_type, [TestQuestionType::SHORT_TEXT, TestQuestionType::LONG_TEXT], true)) {
            $text = trim((string) $answer->answer_text);
            $max = $question->question_type === TestQuestionType::SHORT_TEXT ? 1000 : 10000;

            return $answer->selectedOptions->isEmpty()
                && $answer->file_path === null
                && $text !== ''
                && mb_strlen($text) <= $max;
        }

        return $answer->selectedOptions->isEmpty()
            && $answer->answer_text === null
            && $answer->file_path !== null
            && $answer->file_disk !== null
            && $answer->file_original_name !== null
            && $answer->file_mime_type !== null
            && $answer->file_size !== null
            && Storage::disk($answer->file_disk ?: self::FILE_DISK)->exists($answer->file_path);
    }

    private function loadAnswer(TestAnswer $answer): TestAnswer
    {
        return $answer->load(['question', 'selectedOptions']);
    }
}
