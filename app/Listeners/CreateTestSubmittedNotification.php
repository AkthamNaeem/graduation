<?php

namespace App\Listeners;

use App\Events\TestSubmitted;
use App\Models\TestAttempt;
use App\Services\NotificationService;

class CreateTestSubmittedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(TestSubmitted $event): void
    {
        $attempt = TestAttempt::query()
            ->with([
                'applicationTestAssignment.test',
                'applicationTestAssignment.jobApplication.jobPosting.company.employerProfiles.user',
            ])
            ->find($event->testAttemptId);

        $assignment = $attempt?->applicationTestAssignment;
        $application = $assignment?->jobApplication;
        $job = $application?->jobPosting;

        if ($attempt === null || $assignment === null || $application === null || $job === null) {
            return;
        }

        $employers = $job->company?->employerProfiles
            ->pluck('user')
            ->filter()
            ->values() ?? collect();

        $this->notificationService->createForUsers(
            $employers,
            'test.submitted',
            'Test submitted',
            "A candidate submitted {$assignment->test->title} for {$job->title}.",
            [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'job_title' => $job->title,
                'company_id' => $job->company_id,
                'test_assignment_id' => $assignment->id,
                'test_attempt_id' => $attempt->id,
            ],
        );
    }
}
