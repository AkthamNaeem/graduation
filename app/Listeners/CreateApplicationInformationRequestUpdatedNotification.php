<?php

namespace App\Listeners;

use App\Events\ApplicationInformationRequestUpdated;
use App\Models\ApplicationInformationRequest;

class CreateApplicationInformationRequestUpdatedNotification extends IdempotentNotificationListener
{
    public function handle(ApplicationInformationRequestUpdated $event): void
    {
        $request = ApplicationInformationRequest::query()->with('jobApplication.jobSeekerProfile.user')->find($event->requestId);
        $candidate = $request?->jobApplication?->jobSeekerProfile?->user;
        if ($request === null || $candidate === null) {
            return;
        }
        $this->notificationOnce(
            'application.information_request_updated',
            ApplicationInformationRequestUpdated::class,
            'information_request',
            $request->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'application.information_request_updated', 'Information request updated', 'The information requested for your application was updated.', ['application_id' => $request->job_application_id, 'information_request_id' => $request->id, 'due_at' => $request->due_at?->toISOString()]),
            $event->occurrenceId,
        );
    }
}
