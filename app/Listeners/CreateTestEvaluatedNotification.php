<?php

namespace App\Listeners;

use App\Events\TestEvaluated;
use App\Models\TestAttempt;
use App\Services\NotificationService;

class CreateTestEvaluatedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(TestEvaluated $event): void
    {
        $attempt = TestAttempt::query()
            ->with(['applicationTestAssignment.test', 'applicationTestAssignment.jobApplication.jobSeekerProfile.user'])
            ->find($event->testAttemptId);

        $assignment = $attempt?->applicationTestAssignment;
        $candidate = $assignment?->jobApplication?->jobSeekerProfile?->user;

        if ($attempt === null || $assignment === null || $candidate === null) {
            return;
        }

        $this->notificationService->createNotification(
            $candidate->id,
            'test_evaluated',
            'Test evaluated',
            "Your {$assignment->test->title} submission has been evaluated.",
            [
                'test_attempt_id' => $attempt->id,
                'assignment_id' => $assignment->id,
                'job_application_id' => $assignment->job_application_id,
                'job_posting_id' => $assignment->jobApplication->job_posting_id,
                'test_id' => $assignment->test_id,
                'score' => $attempt->score,
                'evaluated_by_user_id' => $attempt->evaluated_by_user_id,
            ],
        );
    }
}
