<?php

namespace App\Listeners;

use App\Events\TestAssignmentDeadlineExtended;
use App\Models\ApplicationTestAssignment;

class CreateTestAssignmentDeadlineExtendedNotification extends IdempotentNotificationListener
{
    public function handle(TestAssignmentDeadlineExtended $event): void
    {
        $assignment = ApplicationTestAssignment::query()
            ->with('jobApplication.jobSeekerProfile.user')
            ->find($event->assignmentId);
        $candidate = $assignment?->jobApplication?->jobSeekerProfile?->user;

        if ($assignment === null || $candidate === null) {
            return;
        }

        $this->notificationOnce(
            'test.deadline_extended',
            TestAssignmentDeadlineExtended::class,
            'test_assignment',
            $assignment->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'test.deadline_extended', 'Test deadline extended', 'Your test deadline has been extended.', [
                'assignment_id' => $assignment->id,
                'new_deadline_at' => $assignment->deadline_at?->toISOString(),
            ]),
            $event->deadlineChangeId,
        );
    }
}
