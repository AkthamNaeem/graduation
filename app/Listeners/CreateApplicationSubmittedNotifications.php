<?php

namespace App\Listeners;

use App\Events\ApplicationSubmitted;
use App\Models\JobApplication;

class CreateApplicationSubmittedNotifications extends IdempotentNotificationListener
{
    public function handle(ApplicationSubmitted $event): void
    {
        $application = JobApplication::query()
            ->with([
                'jobPosting.company.employerProfiles.user',
                'jobSeekerProfile.user',
            ])
            ->find($event->jobApplicationId);

        $candidate = $application?->jobSeekerProfile?->user;
        $job = $application?->jobPosting;

        if ($application === null || $candidate === null || $job === null) {
            return;
        }

        $data = [
            'application_id' => $application->id,
            'job_id' => $job->id,
            'job_title' => $job->title,
            'company_id' => $job->company_id,
            'status' => 'submitted',
        ];

        $this->notificationOnce(
            'application.submitted',
            ApplicationSubmitted::class,
            'job_application',
            $application->id,
            $candidate,
            fn () => $this->notificationService->createForUser(
                $candidate,
                'application.submitted',
                'Application submitted',
                "Your application for {$job->title} was submitted successfully.",
                $data,
            ),
        );

        $employers = $job->company?->employerProfiles
            ->pluck('user')
            ->filter()
            ->values() ?? collect();

        foreach ($employers as $employer) {
            $this->notificationOnce(
                'application.received',
                ApplicationSubmitted::class,
                'job_application',
                $application->id,
                $employer,
                fn () => $this->notificationService->createForUser(
                    $employer,
                    'application.received',
                    'New application received',
                    "A candidate applied for {$job->title}.",
                    $data,
                ),
            );
        }
    }
}
