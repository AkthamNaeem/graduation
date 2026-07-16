<?php

namespace App\Services;

use App\Enums\TestQuestionType;
use App\Models\Test;
use App\Models\TestOption;
use App\Models\TestQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TestQuestionService
{
    public function __construct(
        private readonly TestService $testService,
    ) {}

    /** @return Collection<int, TestQuestion> */
    public function listQuestions(Test $test): Collection
    {
        return $test->questions()->with('options')->get();
    }

    public function getQuestion(Test $test, TestQuestion $question): TestQuestion
    {
        $this->ensureQuestionBelongsToTest($test, $question);

        return $question->load('options');
    }

    /** @param array<string, mixed> $data */
    public function createQuestion(Test $test, array $data): TestQuestion
    {
        $this->testService->ensureTestIsMutable($test);
        $this->validateOptionSet(TestQuestionType::from($data['question_type']), $data['options'] ?? []);

        return DB::transaction(function () use ($test, $data): TestQuestion {
            $options = $data['options'] ?? [];
            unset($data['options']);

            $question = $test->questions()->create($data);
            $this->replaceOptions($question, $options);

            return $question->load('options');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateQuestion(Test $test, TestQuestion $question, array $data): TestQuestion
    {
        $this->ensureQuestionBelongsToTest($test, $question);
        $this->testService->ensureTestIsMutable($test);

        return DB::transaction(function () use ($question, $data): TestQuestion {
            $question = TestQuestion::query()->with('options')->lockForUpdate()->findOrFail($question->id);
            $type = isset($data['question_type'])
                ? TestQuestionType::from($data['question_type'])
                : $question->question_type;
            $options = array_key_exists('options', $data)
                ? $data['options']
                : $question->options->map->only(['option_text', 'order_index', 'is_correct'])->all();

            $this->validateOptionSet($type, $options);
            $replaceOptions = array_key_exists('options', $data);
            unset($data['options']);
            $question->update($data);

            if ($replaceOptions) {
                $question->options()->delete();
                $this->replaceOptions($question, $options);
            }

            return $question->refresh()->load('options');
        });
    }

    public function deleteQuestion(Test $test, TestQuestion $question): void
    {
        $this->ensureQuestionBelongsToTest($test, $question);
        $this->testService->ensureTestIsMutable($test);
        $question->delete();
    }

    /** @param array<int, array{question_id:int, order_index:int}> $items */
    public function reorderQuestions(Test $test, array $items): Collection
    {
        $this->testService->ensureTestIsMutable($test);
        $existingIds = $test->questions()->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $submittedIds = collect($items)->pluck('question_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        if ($existingIds !== $submittedIds) {
            throw ValidationException::withMessages([
                'questions' => ['The reorder payload must contain every question in this test exactly once.'],
            ]);
        }

        DB::transaction(function () use ($test, $items): void {
            $offset = ((int) $test->questions()->max('order_index')) + count($items) + 1000;

            foreach ($items as $item) {
                TestQuestion::query()->whereKey($item['question_id'])->update([
                    'order_index' => $item['order_index'] + $offset,
                ]);
            }

            foreach ($items as $item) {
                TestQuestion::query()->whereKey($item['question_id'])->update([
                    'order_index' => $item['order_index'],
                ]);
            }
        });

        return $this->listQuestions($test);
    }

    /** @param array<string, mixed> $data */
    public function createOption(Test $test, TestQuestion $question, array $data): TestOption
    {
        $this->ensureQuestionBelongsToTest($test, $question);
        $this->testService->ensureTestIsMutable($test);
        $options = $question->options()->get()->map->only(['option_text', 'order_index', 'is_correct'])->all();
        $options[] = $data;
        $this->validateOptionSet($question->question_type, $options);

        return $question->options()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function updateOption(Test $test, TestQuestion $question, TestOption $option, array $data): TestOption
    {
        $this->ensureOptionHierarchy($test, $question, $option);
        $this->testService->ensureTestIsMutable($test);
        $options = $question->options()->get()->map(function (TestOption $current) use ($option, $data): array {
            $values = $current->only(['option_text', 'order_index', 'is_correct']);

            return $current->is($option) ? array_merge($values, $data) : $values;
        })->all();
        $this->validateOptionSet($question->question_type, $options);
        $option->update($data);

        return $option->refresh();
    }

    public function deleteOption(Test $test, TestQuestion $question, TestOption $option): void
    {
        $this->ensureOptionHierarchy($test, $question, $option);
        $this->testService->ensureTestIsMutable($test);
        $remaining = $question->options()->whereKeyNot($option->id)->get()
            ->map->only(['option_text', 'order_index', 'is_correct'])->all();
        $this->validateOptionSet($question->question_type, $remaining);
        $option->delete();
    }

    /** @param array<int, array{option_id:int, order_index:int}> $items */
    public function reorderOptions(Test $test, TestQuestion $question, array $items): Collection
    {
        $this->ensureQuestionBelongsToTest($test, $question);
        $this->testService->ensureTestIsMutable($test);
        $existingIds = $question->options()->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $submittedIds = collect($items)->pluck('option_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        if ($existingIds !== $submittedIds) {
            throw ValidationException::withMessages([
                'options' => ['The reorder payload must contain every option in this question exactly once.'],
            ]);
        }

        DB::transaction(function () use ($question, $items): void {
            $offset = ((int) $question->options()->max('order_index')) + count($items) + 1000;
            foreach ($items as $item) {
                TestOption::query()->whereKey($item['option_id'])->update(['order_index' => $item['order_index'] + $offset]);
            }
            foreach ($items as $item) {
                TestOption::query()->whereKey($item['option_id'])->update(['order_index' => $item['order_index']]);
            }
        });

        return $question->options()->get();
    }

    /** @param array<int, array<string, mixed>> $options */
    public function validateOptionSet(TestQuestionType $type, array $options): void
    {
        if (! $type->acceptsOptions()) {
            if ($options !== []) {
                throw ValidationException::withMessages(['options' => ['Options are not allowed for this question type.']]);
            }

            return;
        }

        if (count($options) < 2) {
            throw ValidationException::withMessages(['options' => ['Choice questions require at least two options.']]);
        }

        $texts = collect($options)->pluck('option_text')->map(fn ($text): string => mb_strtolower(trim((string) $text)));
        if ($texts->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['options' => ['Option text must be unique within a question.']]);
        }

        $orders = collect($options)->pluck('order_index');
        if ($orders->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['options' => ['Option order indexes must be unique within a question.']]);
        }

        $correctCount = collect($options)->filter(fn (array $option): bool => (bool) ($option['is_correct'] ?? false))->count();
        if ($type === TestQuestionType::SINGLE_CHOICE && $correctCount !== 1) {
            throw ValidationException::withMessages(['options' => ['Single choice questions require exactly one correct option.']]);
        }
        if ($type === TestQuestionType::MULTIPLE_CHOICE && $correctCount < 1) {
            throw ValidationException::withMessages(['options' => ['Multiple choice questions require at least one correct option.']]);
        }
        if ($type === TestQuestionType::TRUE_FALSE) {
            $labels = $texts->sort()->values()->all();
            if (count($options) !== 2 || $labels !== ['false', 'true'] || $correctCount !== 1) {
                throw ValidationException::withMessages(['options' => ['True/false questions require exactly True and False options with one correct answer.']]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $options */
    private function replaceOptions(TestQuestion $question, array $options): void
    {
        foreach ($options as $option) {
            $question->options()->create($option);
        }
    }

    private function ensureQuestionBelongsToTest(Test $test, TestQuestion $question): void
    {
        abort_unless($question->test_id === $test->id, 404);
    }

    private function ensureOptionHierarchy(Test $test, TestQuestion $question, TestOption $option): void
    {
        $this->ensureQuestionBelongsToTest($test, $question);
        abort_unless($option->test_question_id === $question->id, 404);
    }
}
