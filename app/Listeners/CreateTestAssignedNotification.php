<?php

namespace App\Listeners;

use App\Events\TestAssigned;
use App\Models\ApplicationTestAssignment;
use App\Services\NotificationService;

class CreateTestAssignedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(TestAssigned $event): void
    {
        $assignment = ApplicationTestAssignment::query()
            ->with(['test', 'jobApplication.jobPosting', 'jobApplication.jobSeekerProfile.user'])
            ->find($event->assignmentId);

        $candidate = $assignment?->jobApplication?->jobSeekerProfile?->user;

        if ($assignment === null || $candidate === null) {
            return;
        }

        $this->notificationService->createNotification(
            $candidate->id,
            'test_assigned',
            'New test assigned',
            "You have been assigned {$assignment->test->title}.",
            [
                'assignment_id' => $assignment->id,
                'job_application_id' => $assignment->job_application_id,
                'job_posting_id' => $assignment->jobApplication->job_posting_id,
                'test_id' => $assignment->test_id,
                'assigned_by_user_id' => $assignment->assigned_by_user_id,
            ],
        );
    }
}
