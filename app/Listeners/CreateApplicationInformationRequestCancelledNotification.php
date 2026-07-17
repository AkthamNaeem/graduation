<?php

namespace App\Listeners;

use App\Events\ApplicationInformationRequestCancelled;
use App\Models\ApplicationInformationRequest;
use App\Services\NotificationService;

class CreateApplicationInformationRequestCancelledNotification
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(ApplicationInformationRequestCancelled $event): void
    {
        $request = ApplicationInformationRequest::query()->with('jobApplication.jobSeekerProfile.user')->find($event->requestId);
        $candidate = $request?->jobApplication?->jobSeekerProfile?->user;
        if ($request === null || $candidate === null) {
            return;
        }
        $this->notifications->createForUser($candidate, 'application.information_request_cancelled', 'Information request cancelled', 'The additional information request was cancelled.', ['application_id' => $request->job_application_id, 'information_request_id' => $request->id]);
    }
}
