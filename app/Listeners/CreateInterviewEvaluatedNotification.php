<?php

namespace App\Listeners;

use App\Events\InterviewEvaluated;
use App\Models\Interview;
use App\Services\NotificationService;

class CreateInterviewEvaluatedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(InterviewEvaluated $event): void
    {
        $interview = Interview::query()
            ->with(['evaluation', 'jobApplication.jobPosting', 'jobApplication.jobSeekerProfile.user'])
            ->find($event->interviewId);

        $candidate = $interview?->jobApplication?->jobSeekerProfile?->user;

        if ($interview === null || $candidate === null || $interview->evaluation === null) {
            return;
        }

        $this->notificationService->createNotification(
            $candidate->id,
            'interview_evaluated',
            'Interview evaluated',
            "Your interview for {$interview->jobApplication->jobPosting->title} has been evaluated.",
            [
                'interview_id' => $interview->id,
                'job_application_id' => $interview->job_application_id,
                'job_posting_id' => $interview->jobApplication->job_posting_id,
                'recommendation' => $interview->evaluation->recommendation,
                'evaluated_by_user_id' => $interview->evaluation->evaluated_by_user_id,
            ],
        );
    }
}
