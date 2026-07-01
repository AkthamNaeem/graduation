<?php

namespace App\Listeners;

use App\Events\InterviewUpdated;
use App\Models\Interview;
use App\Services\NotificationService;

class CreateInterviewUpdatedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(InterviewUpdated $event): void
    {
        $interview = Interview::query()
            ->with(['jobApplication.jobPosting', 'jobApplication.jobSeekerProfile.user'])
            ->find($event->interviewId);

        $candidate = $interview?->jobApplication?->jobSeekerProfile?->user;
        $job = $interview?->jobApplication?->jobPosting;

        if ($interview === null || $candidate === null || $job === null) {
            return;
        }

        $this->notificationService->createForUser(
            $candidate,
            'interview.rescheduled',
            'Interview updated',
            "Your interview for {$job->title} has been updated.",
            [
                'application_id' => $interview->job_application_id,
                'job_id' => $job->id,
                'job_title' => $job->title,
                'company_id' => $job->company_id,
                'interview_id' => $interview->id,
                'scheduled_at' => $interview->scheduled_at?->toISOString(),
            ],
        );
    }
}
