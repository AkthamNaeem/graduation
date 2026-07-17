<?php

namespace App\Listeners;

use App\Events\InterviewNoShow;
use App\Models\Interview;

class CreateInterviewNoShowNotifications extends IdempotentNotificationListener
{
    public function handle(InterviewNoShow $event): void
    {
        $interview = Interview::query()->with(['scheduledBy', 'jobApplication.jobSeekerProfile.user'])->find($event->interviewId);
        if ($interview === null) {
            return;
        }

        $recipients = collect([$interview->scheduledBy, $interview->jobApplication?->jobSeekerProfile?->user])->filter()->unique('id');
        foreach ($recipients as $recipient) {
            $this->notificationOnce(
                'interview.no_show', InterviewNoShow::class, 'interview', $interview->id, $recipient,
                fn () => $this->notificationService->createForUser($recipient, 'interview.no_show', 'Interview marked as no show', 'The interview was closed because a participant did not attend.', [
                    'interview_id' => $interview->id,
                    'application_id' => $interview->job_application_id,
                    'status' => 'no_show',
                ]),
                $event->historyId,
            );
        }
    }
}
