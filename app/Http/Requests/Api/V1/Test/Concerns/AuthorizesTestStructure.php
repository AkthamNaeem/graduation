<?php

namespace App\Http\Requests\Api\V1\Test\Concerns;

use App\Models\Test;
use App\Models\TestOption;
use App\Models\TestQuestion;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

trait AuthorizesTestStructure
{
    protected function authorizedTest(): ?Test
    {
        $test = $this->route('test');

        return $test instanceof Test ? $test : null;
    }

    protected function authorizedQuestion(): ?TestQuestion
    {
        $question = $this->route('question');

        return $question instanceof TestQuestion ? $question : null;
    }

    protected function authorizedOption(): ?TestOption
    {
        $option = $this->route('option');

        return $option instanceof TestOption ? $option : null;
    }

    protected function canManageTestStructure(bool $requireQuestion = false, bool $requireOption = false): bool
    {
        $test = $this->authorizedTest();
        $question = $this->authorizedQuestion();
        $option = $this->authorizedOption();

        if (! $test || ! ($this->authenticatedTestUser()?->can('manageQuestions', $test) ?? false)) {
            return false;
        }

        if ($requireQuestion && (! $question || $question->test_id !== $test->id)) {
            return false;
        }

        return ! $requireOption || ($option && $question && $option->test_question_id === $question->id);
    }

    protected function authenticatedTestUser(): ?User
    {
        $token = $this->bearerToken();
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User ? $tokenable->withAccessToken($accessToken) : null;
    }
}
