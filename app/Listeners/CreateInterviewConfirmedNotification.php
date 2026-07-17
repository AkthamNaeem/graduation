<?php

namespace App\Listeners;

use App\Events\InterviewConfirmed;
use App\Models\Interview;

class CreateInterviewConfirmedNotification extends IdempotentNotificationListener
{
    public function handle(InterviewConfirmed $event): void
    {
        $interview = Interview::query()->with(['scheduledBy', 'jobApplication.jobPosting'])->find($event->interviewId);
        $recipient = $interview?->scheduledBy;
        if ($interview === null || $recipient === null) {
            return;
        }

        $this->notificationOnce(
            'interview.confirmed', InterviewConfirmed::class, 'interview', $interview->id, $recipient,
            fn () => $this->notificationService->createForUser($recipient, 'interview.confirmed', 'Interview confirmed', 'The candidate confirmed the interview.', [
                'interview_id' => $interview->id,
                'application_id' => $interview->job_application_id,
                'scheduled_start_at' => $interview->scheduled_at?->toISOString(),
            ]),
            $event->historyId,
        );
    }
}
