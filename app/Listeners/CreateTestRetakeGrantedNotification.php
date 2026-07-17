<?php

namespace App\Listeners;

use App\Events\TestRetakeGranted;
use App\Models\ApplicationTestAssignment;

class CreateTestRetakeGrantedNotification extends IdempotentNotificationListener
{
    public function handle(TestRetakeGranted $event): void
    {
        $assignment = ApplicationTestAssignment::query()
            ->with('jobApplication.jobSeekerProfile.user')
            ->find($event->assignmentId);
        $candidate = $assignment?->jobApplication?->jobSeekerProfile?->user;

        if ($assignment === null || $candidate === null) {
            return;
        }

        $this->notificationOnce(
            'test.retake_granted',
            TestRetakeGranted::class,
            'test_assignment',
            $assignment->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'test.retake_granted', 'Test retake granted', 'A new test attempt is available.', [
                'assignment_id' => $assignment->id,
                'attempt_number' => $assignment->attempt_number,
                'max_attempts' => $assignment->max_attempts,
                'deadline_at' => $assignment->deadline_at?->toISOString(),
            ]),
        );
    }
}
