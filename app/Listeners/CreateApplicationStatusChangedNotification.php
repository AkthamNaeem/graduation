<?php

namespace App\Listeners;

use App\Events\ApplicationStatusChanged;
use App\Models\JobApplication;

class CreateApplicationStatusChangedNotification extends IdempotentNotificationListener
{
    public function handle(ApplicationStatusChanged $event): void
    {
        $application = JobApplication::query()
            ->with(['jobPosting', 'jobSeekerProfile.user'])
            ->find($event->jobApplicationId);

        $candidate = $application?->jobSeekerProfile?->user;

        if ($application === null || $candidate === null) {
            return;
        }

        $job = $application->jobPosting;
        $jobTitle = $job?->title ?? 'your application';
        $type = match ($event->toStatus) {
            'need_more_information' => 'application.need_more_information',
            'accepted' => 'final.accepted',
            'rejected' => 'final.rejected',
            default => 'application.status_changed',
        };
        $title = match ($event->toStatus) {
            'need_more_information' => 'More information requested',
            'accepted' => 'Application accepted',
            'rejected' => 'Application rejected',
            default => 'Application status updated',
        };
        $message = match ($event->toStatus) {
            'need_more_information' => "The employer requested more information for your {$jobTitle} application.",
            'accepted' => "Your application for {$jobTitle} has been accepted.",
            'rejected' => "Your application for {$jobTitle} was not selected.",
            default => "Your application for {$jobTitle} moved to {$event->toStatus}.",
        };

        $this->notificationOnce(
            $type,
            ApplicationStatusChanged::class,
            'job_application',
            $application->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, $type, $title, $message, [
                'application_id' => $application->id,
                'job_id' => $application->job_posting_id,
                'job_title' => $jobTitle,
                'company_id' => $job?->company_id,
                'status' => $event->toStatus,
            ]),
            $event->historyId,
        );
    }
}
