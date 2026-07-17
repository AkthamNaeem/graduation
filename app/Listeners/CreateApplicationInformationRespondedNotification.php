<?php

namespace App\Listeners;

use App\Events\ApplicationInformationResponded;
use App\Models\ApplicationInformationRequest;
use App\Services\NotificationService;

class CreateApplicationInformationRespondedNotification
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(ApplicationInformationResponded $event): void
    {
        $request = ApplicationInformationRequest::query()->with(['requestedBy', 'response.attachments'])->find($event->requestId);
        if ($request === null || $request->requestedBy === null || $request->response === null) {
            return;
        }
        $this->notifications->createForUser($request->requestedBy, 'application.information_submitted', 'Requested information submitted', 'The candidate submitted the requested information.', ['application_id' => $request->job_application_id, 'information_request_id' => $request->id, 'response_id' => $request->response->id, 'submitted_at' => $request->response->submitted_at?->toISOString(), 'attachment_count' => $request->response->attachments->count()]);
    }
}
