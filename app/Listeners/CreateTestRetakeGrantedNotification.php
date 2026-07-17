<?php

namespace App\Listeners;

use App\Events\TestRetakeGranted;
use App\Models\ApplicationTestAssignment;
use App\Services\NotificationService;

class CreateTestRetakeGrantedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(TestRetakeGranted $event): void
    {
        $assignment = ApplicationTestAssignment::query()
            ->with('jobApplication.jobSeekerProfile.user')
            ->find($event->assignmentId);
        $candidate = $assignment?->jobApplication?->jobSeekerProfile?->user;

        if ($assignment === null || $candidate === null) {
            return;
        }

        $this->notificationService->createNotification(
            $candidate->id,
            'test.retake_granted',
            'Test retake granted',
            'A new test attempt is available.',
            [
                'assignment_id' => $assignment->id,
                'attempt_number' => $assignment->attempt_number,
                'max_attempts' => $assignment->max_attempts,
                'deadline_at' => $assignment->deadline_at?->toISOString(),
            ],
        );
    }
}
