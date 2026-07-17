<?php

namespace App\Listeners;

use App\Events\InterviewEvaluated;
use App\Models\Interview;

class CreateInterviewEvaluatedNotification extends IdempotentNotificationListener
{
    public function handle(InterviewEvaluated $event): void
    {
        $interview = Interview::query()
            ->with(['evaluation', 'jobApplication.jobPosting', 'jobApplication.jobSeekerProfile.user'])
            ->find($event->interviewId);

        $candidate = $interview?->jobApplication?->jobSeekerProfile?->user;

        if ($interview === null || $candidate === null || $interview->evaluation === null) {
            return;
        }

        $this->notificationOnce(
            'interview.evaluated',
            InterviewEvaluated::class,
            'interview',
            $interview->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'interview.evaluated', 'Interview evaluated', "Your interview for {$interview->jobApplication->jobPosting->title} has been evaluated.", [
                'interview_id' => $interview->id,
                'application_id' => $interview->job_application_id,
                'job_id' => $interview->jobApplication->job_posting_id,
                'job_title' => $interview->jobApplication->jobPosting?->title,
                'company_id' => $interview->jobApplication->jobPosting?->company_id,
                'status' => 'final_review',
            ]),
            $event->historyId,
        );
    }
}
