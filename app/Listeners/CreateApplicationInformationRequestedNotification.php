<?php

namespace App\Listeners;

use App\Events\ApplicationInformationRequested;
use App\Models\ApplicationInformationRequest;

class CreateApplicationInformationRequestedNotification extends IdempotentNotificationListener
{
    public function handle(ApplicationInformationRequested $event): void
    {
        $request = ApplicationInformationRequest::query()->with(['jobApplication.jobSeekerProfile.user', 'items'])->find($event->requestId);
        $candidate = $request?->jobApplication?->jobSeekerProfile?->user;
        if ($request === null || $candidate === null) {
            return;
        }
        $this->notificationOnce(
            'application.information_requested',
            ApplicationInformationRequested::class,
            'information_request',
            $request->id,
            $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'application.information_requested', 'Additional information requested', 'An employer requested additional information for your application.', ['application_id' => $request->job_application_id, 'information_request_id' => $request->id, 'message_summary' => str($request->message)->limit(160)->toString(), 'due_at' => $request->due_at?->toISOString(), 'requested_items_count' => $request->items->count()]),
        );
    }
}
