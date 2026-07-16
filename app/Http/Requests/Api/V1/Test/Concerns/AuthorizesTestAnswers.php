<?php

namespace App\Http\Requests\Api\V1\Test\Concerns;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\TestAttempt;

trait AuthorizesTestAnswers
{
    use ResolvesApplicationUser;

    protected function canViewAnswers(): bool
    {
        $attempt = $this->route('testAttempt');

        return $attempt instanceof TestAttempt
            && ($this->authenticatedUser()?->can('viewAnswers', $attempt) ?? false);
    }

    protected function canManageAnswers(): bool
    {
        $attempt = $this->route('testAttempt');

        return $attempt instanceof TestAttempt
            && ($this->authenticatedUser()?->can('manageAnswers', $attempt) ?? false);
    }

    protected function canDownloadAnswer(): bool
    {
        $attempt = $this->route('testAttempt');

        return $attempt instanceof TestAttempt
            && ($this->authenticatedUser()?->can('downloadAnswer', $attempt) ?? false);
    }
}
