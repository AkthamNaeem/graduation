<?php

namespace App\Services;

use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use App\Models\TestAttempt;

/**
 * Shared candidate-action classification used by Applications, Home and Activity.
 * Presentation details and route choices remain the responsibility of each page.
 */
class CandidateActionResolver
{
    /** @return array{requires_action:bool,state:string,attempt:?TestAttempt,due_at:mixed,is_overdue:bool} */
    public function test(ApplicationTestAssignment $assignment): array
    {
        $attempt = $assignment->relationLoaded('testAttempts')
            ? $assignment->testAttempts->sortByDesc('id')->first()
            : ($assignment->relationLoaded('testAttempt') ? $assignment->testAttempt : null);
        $dueAt = $attempt?->effective_deadline_at ?? $assignment->deadline_at;
        $submitted = $attempt?->submitted_at !== null;

        return [
            'requires_action' => ! $submitted,
            'state' => $submitted ? 'submitted' : ($attempt === null ? 'not_started' : 'in_progress'),
            'attempt' => $attempt,
            'due_at' => $dueAt,
            'is_overdue' => ! $submitted && $dueAt !== null && now()->isAfter($dueAt),
        ];
    }

    /** @return array{requires_action:bool,is_overdue:bool} */
    public function informationRequest(ApplicationInformationRequest $request): array
    {
        return [
            'requires_action' => $request->canBeRespondedTo(),
            'is_overdue' => $request->isExpired(),
        ];
    }

    /** @return array{requires_action:bool,is_upcoming:bool} */
    public function interview(Interview $interview): array
    {
        $upcoming = in_array($interview->status, ['scheduled', 'confirmed', 'rescheduled'], true)
            && $interview->scheduled_at?->isFuture();

        return [
            'requires_action' => $upcoming
                && in_array($interview->status, ['scheduled', 'rescheduled'], true)
                && $interview->confirmed_at === null,
            'is_upcoming' => $upcoming,
        ];
    }
}
