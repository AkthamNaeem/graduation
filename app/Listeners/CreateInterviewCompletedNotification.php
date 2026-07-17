<?php

namespace App\Listeners;

use App\Events\InterviewCompleted;
use App\Models\Interview;

class CreateInterviewCompletedNotification extends IdempotentNotificationListener
{
    public function handle(InterviewCompleted $event): void
    {
        $interview = Interview::query()->with('jobApplication.jobSeekerProfile.user')->find($event->interviewId);
        $candidate = $interview?->jobApplication?->jobSeekerProfile?->user;
        if ($interview === null || $candidate === null) {
            return;
        }

        $this->notificationOnce(
            'interview.completed', InterviewCompleted::class, 'interview', $interview->id, $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'interview.completed', 'Interview completed', 'Your interview has been marked as completed.', [
                'interview_id' => $interview->id,
                'application_id' => $interview->job_application_id,
                'status' => 'completed',
            ]),
            $event->historyId,
        );
    }
}
