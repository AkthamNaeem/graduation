<?php

namespace App\Listeners;

use App\Events\TestEvaluated;
use App\Models\TestAttempt;

class CreateTestEvaluatedNotification extends IdempotentNotificationListener
{
    public function handle(TestEvaluated $event): void
    {
        $attempt = TestAttempt::query()
            ->with([
                'applicationTestAssignment.test',
                'applicationTestAssignment.jobApplication.jobPosting',
                'applicationTestAssignment.jobApplication.jobSeekerProfile.user',
            ])
            ->find($event->testAttemptId);

        $assignment = $attempt?->applicationTestAssignment;
        $candidate = $assignment?->jobApplication?->jobSeekerProfile?->user;

        if ($attempt === null || $assignment === null || $candidate === null) {
            return;
        }

        $this->notificationOnce(
            'test.evaluated',
            TestEvaluated::class,
            'test_attempt',
            $attempt->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'test.evaluated', 'Test evaluated', "Your {$assignment->test->title} submission has been evaluated.", [
                'test_attempt_id' => $attempt->id,
                'test_assignment_id' => $assignment->id,
                'application_id' => $assignment->job_application_id,
                'job_id' => $assignment->jobApplication->job_posting_id,
                'job_title' => $assignment->jobApplication->jobPosting?->title,
                'company_id' => $assignment->jobApplication->jobPosting?->company_id,
                'test_id' => $assignment->test_id,
                'status' => 'test_completed',
            ]),
        );
    }
}
