<?php

namespace App\Listeners;

use App\Events\InterviewAttendanceUpdated;
use App\Models\Interview;

class CreateInterviewAttendanceUpdatedNotification extends IdempotentNotificationListener
{
    public function handle(InterviewAttendanceUpdated $event): void
    {
        $interview = Interview::query()->with('jobApplication.jobSeekerProfile.user')->find($event->interviewId);
        $candidate = $interview?->jobApplication?->jobSeekerProfile?->user;
        if ($interview === null || $candidate === null) {
            return;
        }

        $this->notificationOnce(
            'interview.attendance_updated', InterviewAttendanceUpdated::class, 'interview', $interview->id, $candidate,
            fn () => $this->notificationService->createForUser($candidate, 'interview.attendance_updated', __('notifications.attendance_updated_title'), __('notifications.attendance_updated_body'), [
                'interview_id' => $interview->id,
                'application_id' => $interview->job_application_id,
                'candidate_attendance_status' => $interview->candidate_attendance_status,
            ]),
            $event->occurrenceId,
        );
    }
}
