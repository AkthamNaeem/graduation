<?php

namespace App\Listeners;

use App\Events\TestAssignmentDeadlineExtended;
use App\Models\ApplicationTestAssignment;
use App\Services\NotificationService;

class CreateTestAssignmentDeadlineExtendedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(TestAssignmentDeadlineExtended $event): void
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
            'test.deadline_extended',
            'Test deadline extended',
            'Your test deadline has been extended.',
            [
                'assignment_id' => $assignment->id,
                'new_deadline_at' => $assignment->deadline_at?->toISOString(),
            ],
        );
    }
}
