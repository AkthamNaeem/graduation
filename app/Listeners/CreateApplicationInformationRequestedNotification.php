<?php

namespace App\Listeners;

use App\Events\ApplicationInformationRequested;
use App\Models\ApplicationInformationRequest;
use App\Services\NotificationService;

class CreateApplicationInformationRequestedNotification
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(ApplicationInformationRequested $event): void
    {
        $request = ApplicationInformationRequest::query()->with(['jobApplication.jobSeekerProfile.user', 'items'])->find($event->requestId);
        $candidate = $request?->jobApplication?->jobSeekerProfile?->user;
        if ($request === null || $candidate === null) {
            return;
        }
        $this->notifications->createForUser($candidate, 'application.information_requested', 'Additional information requested', 'An employer requested additional information for your application.', ['application_id' => $request->job_application_id, 'information_request_id' => $request->id, 'message_summary' => str($request->message)->limit(160)->toString(), 'due_at' => $request->due_at?->toISOString(), 'requested_items_count' => $request->items->count()]);
    }
}
