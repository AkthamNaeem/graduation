<?php

namespace App\Listeners;

use App\Events\TestAssigned;
use App\Models\ApplicationTestAssignment;

class CreateTestAssignedNotification extends IdempotentNotificationListener
{
    public function handle(TestAssigned $event): void
    {
        $assignment = ApplicationTestAssignment::query()
            ->with(['test', 'jobApplication.jobPosting', 'jobApplication.jobSeekerProfile.user'])
            ->find($event->assignmentId);

        $candidate = $assignment?->jobApplication?->jobSeekerProfile?->user;

        if ($assignment === null || $candidate === null) {
            return;
        }

        $this->notificationOnce(
            'test.assigned',
            TestAssigned::class,
            'test_assignment',
            $assignment->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'test.assigned', 'New test assigned', "You have been assigned {$assignment->test->title}.", [
                'application_id' => $assignment->job_application_id,
                'job_id' => $assignment->jobApplication->job_posting_id,
                'job_title' => $assignment->jobApplication->jobPosting?->title,
                'company_id' => $assignment->jobApplication->jobPosting?->company_id,
                'test_assignment_id' => $assignment->id,
                'test_id' => $assignment->test_id,
                'status' => 'test_pending',
                'deadline_at' => $assignment->deadline_at?->toISOString(),
                'attempt_number' => $assignment->attempt_number,
                'max_attempts' => $assignment->max_attempts,
            ]),
        );
    }
}
