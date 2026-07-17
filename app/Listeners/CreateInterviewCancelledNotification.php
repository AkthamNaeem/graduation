<?php

namespace App\Listeners;

use App\Events\InterviewCancelled;
use App\Models\JobApplication;

class CreateInterviewCancelledNotification extends IdempotentNotificationListener
{
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

        $this->notificationOnce(
            'interview.cancelled',
            InterviewCancelled::class,
            'interview',
            $event->interviewId,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'interview.cancelled', 'Interview cancelled', $event->candidateMessage ?: "Your interview for {$job->title} has been cancelled.", [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'job_title' => $job->title,
                'company_id' => $job->company_id,
                'interview_id' => $event->interviewId,
                'scheduled_at' => $event->scheduledAt,
            ]),
            $event->historyId,
        );
    }
}
