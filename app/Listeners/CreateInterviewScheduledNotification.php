<?php

namespace App\Listeners;

use App\Events\InterviewScheduled;
use App\Models\Interview;

class CreateInterviewScheduledNotification extends IdempotentNotificationListener
{
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

        $this->notificationOnce(
            'interview.scheduled',
            InterviewScheduled::class,
            'interview',
            $interview->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'interview.scheduled', 'Interview scheduled', "Your interview for {$interview->jobApplication->jobPosting->title} is scheduled for {$scheduledAt}.", [
                'interview_id' => $interview->id,
                'application_id' => $interview->job_application_id,
                'job_id' => $interview->jobApplication->job_posting_id,
                'job_title' => $interview->jobApplication->jobPosting?->title,
                'company_id' => $interview->jobApplication->jobPosting?->company_id,
                'scheduled_at' => $interview->scheduled_at?->toISOString(),
                'status' => 'interview_scheduled',
            ]),
        );
    }
}
