<?php

namespace App\Listeners;

use App\Events\InterviewScheduled;
use App\Models\Interview;
use App\Services\NotificationService;

class CreateInterviewScheduledNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(InterviewScheduled $event): void
    {
        $interview = Interview::query()
            ->with(['jobApplication.jobPosting', 'jobApplication.jobSeekerProfile.user'])
            ->find($event->interviewId);

        $candidate = $interview?->jobApplication?->jobSeekerProfile?->user;

        if ($interview === null || $candidate === null) {
            return;
        }

        $scheduledAt = $interview->scheduled_at?->toISOString() ?? 'the scheduled time';

        $this->notificationService->createNotification(
            $candidate->id,
            'interview_scheduled',
            'Interview scheduled',
            "Your interview for {$interview->jobApplication->jobPosting->title} is scheduled for {$scheduledAt}.",
            [
                'interview_id' => $interview->id,
                'job_application_id' => $interview->job_application_id,
                'job_posting_id' => $interview->jobApplication->job_posting_id,
                'scheduled_by_user_id' => $interview->scheduled_by_user_id,
                'scheduled_at' => $interview->scheduled_at?->toISOString(),
            ],
        );
    }
}
