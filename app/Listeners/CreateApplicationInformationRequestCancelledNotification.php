<?php

namespace App\Listeners;

use App\Events\ApplicationInformationRequestCancelled;
use App\Models\ApplicationInformationRequest;

class CreateApplicationInformationRequestCancelledNotification extends IdempotentNotificationListener
{
    public function handle(ApplicationInformationRequestCancelled $event): void
    {
        $request = ApplicationInformationRequest::query()->with('jobApplication.jobSeekerProfile.user')->find($event->requestId);
        $candidate = $request?->jobApplication?->jobSeekerProfile?->user;
        if ($request === null || $candidate === null) {
            return;
        }
        $this->notificationOnce(
            'application.information_request_cancelled',
            ApplicationInformationRequestCancelled::class,
            'information_request',
            $request->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'application.information_request_cancelled', 'Information request cancelled', 'The additional information request was cancelled.', ['application_id' => $request->job_application_id, 'information_request_id' => $request->id]),
        );
    }
}
