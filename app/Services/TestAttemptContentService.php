<?php

namespace App\Services;

use App\Exceptions\TestContentAccessException;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use Illuminate\Database\Eloquent\Collection;

class TestAttemptContentService
{
    /** @return Collection<int, TestQuestion> */
    public function questions(TestAttempt $attempt): Collection
    {
        $attempt->loadMissing('applicationTestAssignment');

        if ($attempt->started_at === null) {
            throw new TestContentAccessException(
                'This test attempt has not been started.',
                'TEST_ATTEMPT_NOT_STARTED',
                409,
            );
        }

        $assignment = $attempt->applicationTestAssignment;
        if (! $assignment->isLatestAssignment() && $attempt->submitted_at === null) {
            throw new TestContentAccessException(
                'Test content is unavailable for this superseded assignment.',
                'TEST_CONTENT_UNAVAILABLE',
                409,
            );
        }

        return TestQuestion::query()
            ->select([
                'id',
                'test_id',
                'question_text',
                'question_type',
                'order_index',
                'is_required',
            ])
            ->with(['options' => fn ($query) => $query->select([
                'id',
                'test_question_id',
                'option_text',
                'order_index',
            ])])
            ->where('test_id', $assignment->test_id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
    }
}
