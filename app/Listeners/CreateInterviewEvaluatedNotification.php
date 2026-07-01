<?php

namespace App\Listeners;

use App\Events\InterviewEvaluated;
use App\Models\Interview;
use App\Services\NotificationService;

class CreateInterviewEvaluatedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

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
            'interview.evaluated',
            'Interview evaluated',
            "Your interview for {$interview->jobApplication->jobPosting->title} has been evaluated.",
            [
                'interview_id' => $interview->id,
                'application_id' => $interview->job_application_id,
                'job_id' => $interview->jobApplication->job_posting_id,
                'job_title' => $interview->jobApplication->jobPosting?->title,
                'company_id' => $interview->jobApplication->jobPosting?->company_id,
                'status' => 'final_review',
            ],
        );
    }
}
