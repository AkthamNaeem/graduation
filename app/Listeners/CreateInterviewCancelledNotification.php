<?php

namespace App\Listeners;

use App\Events\InterviewCancelled;
use App\Models\JobApplication;
use App\Services\NotificationService;

class CreateInterviewCancelledNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(InterviewCancelled $event): void
    {
        $application = JobApplication::query()
            ->with(['jobPosting', 'jobSeekerProfile.user'])
            ->find($event->jobApplicationId);

        $candidate = $application?->jobSeekerProfile?->user;
        $job = $application?->jobPosting;

        if ($application === null || $candidate === null || $job === null) {
            return;
        }

        $this->notificationService->createForUser(
            $candidate,
            'interview.cancelled',
            'Interview cancelled',
            "Your interview for {$job->title} has been cancelled.",
            [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'job_title' => $job->title,
                'company_id' => $job->company_id,
                'interview_id' => $event->interviewId,
                'scheduled_at' => $event->scheduledAt,
            ],
        );
    }
}
