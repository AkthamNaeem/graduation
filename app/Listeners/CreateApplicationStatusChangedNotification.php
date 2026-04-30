<?php

namespace App\Listeners;

use App\Events\ApplicationStatusChanged;
use App\Models\JobApplication;
use App\Services\NotificationService;

class CreateApplicationStatusChangedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    public function handle(ApplicationStatusChanged $event): void
    {
        $application = JobApplication::query()
            ->with(['jobPosting', 'jobSeekerProfile.user'])
            ->find($event->jobApplicationId);

        $candidate = $application?->jobSeekerProfile?->user;

        if ($application === null || $candidate === null) {
            return;
        }

        $jobTitle = $application->jobPosting?->title ?? 'your application';

        $this->notificationService->createNotification(
            $candidate->id,
            'application_status_changed',
            'Application status updated',
            "Your application for {$jobTitle} moved to {$event->toStatus}.",
            [
                'job_application_id' => $application->id,
                'job_posting_id' => $application->job_posting_id,
                'from_status' => $event->fromStatus,
                'to_status' => $event->toStatus,
                'changed_by_user_id' => $event->changedByUserId,
                'note' => $event->note,
            ],
        );
    }
}
