<?php

namespace App\Listeners;

use App\Events\InterviewRescheduled;
use App\Models\Interview;

class CreateInterviewRescheduledNotification extends IdempotentNotificationListener
{
    public function handle(InterviewRescheduled $event): void
    {
        $interview = Interview::query()->with(['jobApplication.jobPosting', 'jobApplication.jobSeekerProfile.user'])->find($event->interviewId);
        $candidate = $interview?->jobApplication?->jobSeekerProfile?->user;
        if ($interview === null || $candidate === null) {
            return;
        }

        $this->notificationOnce(
            'interview.rescheduled', InterviewRescheduled::class, 'interview', $interview->id, $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'interview.rescheduled', 'Interview rescheduled', 'Your interview schedule has been updated.', [
                'interview_id' => $interview->id,
                'application_id' => $interview->job_application_id,
                'mode' => $interview->interview_mode,
                'scheduled_start_at' => $interview->scheduled_at?->toISOString(),
                'scheduled_end_at' => $interview->scheduled_end_at?->toISOString(),
            ]),
            $event->scheduleChangeId,
        );
    }
}
