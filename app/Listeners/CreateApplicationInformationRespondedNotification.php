<?php

namespace App\Listeners;

use App\Events\ApplicationInformationResponded;
use App\Models\ApplicationInformationRequest;

class CreateApplicationInformationRespondedNotification extends IdempotentNotificationListener
{
    public function handle(ApplicationInformationResponded $event): void
    {
        $request = ApplicationInformationRequest::query()->with(['requestedBy', 'response.attachments'])->find($event->requestId);
        if ($request === null || $request->requestedBy === null || $request->response === null) {
            return;
        }
        $recipient = $request->requestedBy;
        $response = $request->response;

        $this->notificationOnce(
            'application.information_responded',
            ApplicationInformationResponded::class,
            'information_response',
            $response->id,
            $recipient,
            fn () => $this->notificationService->createForUser($recipient, 'application.information_submitted', 'Requested information submitted', 'The candidate submitted the requested information.', ['application_id' => $request->job_application_id, 'information_request_id' => $request->id, 'response_id' => $response->id, 'submitted_at' => $response->submitted_at?->toISOString(), 'attachment_count' => $response->attachments->count()]),
        );
    }
}
