<?php

namespace Database\Seeders;

use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;

class DemoNotificationsSeeder extends Seeder
{
    public function run(): void
    {
        $now = DemoSeederContext::now();
        $candidate = User::query()->where('email', 'seeker.backend@workey.test')->firstOrFail();
        $otherCandidate = User::query()->where('email', 'seeker.junior@workey.test')->firstOrFail();
        $employer = User::query()->where('email', 'employer.approved@workey.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@workey.test')->firstOrFail();
        $accepted = $this->application('accepted');
        $rejected = $this->application('rejected');
        $assignment = ApplicationTestAssignment::query()->where('job_application_id', $accepted->id)->firstOrFail();
        $interview = Interview::query()->where('status', 'evaluated')->firstOrFail();
        $information = ApplicationInformationRequest::query()->where('status', 'pending')->firstOrFail();

        $rows = [
            [$candidate, 'application.submitted', 'Application submitted', 'Your application was submitted successfully.', ['application_id' => $accepted->id, 'job_id' => $accepted->job_posting_id, 'status' => 'submitted']],
            [$employer, 'application.received', 'New application received', 'A candidate applied to your vacancy.', ['application_id' => $accepted->id, 'job_id' => $accepted->job_posting_id, 'status' => 'submitted']],
            [$candidate, 'application.status_changed', 'Application status updated', 'Your application moved to final review.', ['application_id' => $accepted->id, 'status' => 'final_review']],
            [$candidate, 'test.assigned', 'New test assigned', 'A Laravel assessment is ready.', ['application_id' => $accepted->id, 'test_assignment_id' => $assignment->id, 'status' => 'test_pending']],
            [$employer, 'test.submitted', 'Test submitted', 'The candidate submitted the assigned assessment.', ['application_id' => $accepted->id, 'test_assignment_id' => $assignment->id, 'status' => 'submitted']],
            [$candidate, 'test.evaluated', 'Test evaluated', 'Your assessment was evaluated.', ['application_id' => $accepted->id, 'test_assignment_id' => $assignment->id, 'status' => 'test_completed']],
            [$candidate, 'interview.scheduled', 'Interview scheduled', 'Your interview has been scheduled.', ['interview_id' => $interview->id, 'application_id' => $interview->job_application_id, 'status' => 'interview_scheduled']],
            [$candidate, 'interview.rescheduled', 'Interview rescheduled', 'Your interview schedule was updated.', ['interview_id' => $interview->id, 'application_id' => $interview->job_application_id, 'status' => 'rescheduled']],
            [$candidate, 'interview.cancelled', 'Interview cancelled', 'The interview was cancelled.', ['interview_id' => $interview->id, 'application_id' => $interview->job_application_id, 'status' => 'cancelled']],
            [$candidate, 'final.accepted', 'Application accepted', 'Your application has been accepted.', ['application_id' => $accepted->id, 'status' => 'accepted']],
            [$rejected->jobSeekerProfile->user, 'final.rejected', 'Application rejected', 'Your application was not selected.', ['application_id' => $rejected->id, 'status' => 'rejected']],
            [$otherCandidate, 'application.information_requested', 'Additional information requested', 'An employer requested additional information.', ['application_id' => $information->job_application_id, 'information_request_id' => $information->id, 'due_at' => $information->due_at?->toISOString()]],
            [$employer, 'application.information_submitted', 'Requested information submitted', 'The candidate submitted requested information.', ['application_id' => $accepted->id, 'attachment_count' => 1]],
            [$admin, 'company.approval_attention', 'Company review queue', 'A company is waiting for administrator review.', ['approval_status' => 'pending']],
        ];

        foreach ($rows as $index => [$user, $type, $title, $message, $data]) {
            Notification::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'read_at' => $index % 3 === 0 ? $now->copy()->subHours(12 - min($index, 11)) : null,
                'created_at' => $now->copy()->subHours(24 - $index),
            ]);
        }
    }

    private function application(string $status): JobApplication
    {
        return JobApplication::query()
            ->with('jobSeekerProfile.user')
            ->whereHas('applicationStatus', fn ($query) => $query->where('slug', $status))
            ->firstOrFail();
    }
}
