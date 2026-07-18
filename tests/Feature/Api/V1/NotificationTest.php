<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\ApplicationInformationRequestCancelled;
use App\Events\ApplicationInformationRequested;
use App\Events\ApplicationStatusChanged;
use App\Events\ApplicationSubmitted;
use App\Events\InterviewCancelled;
use App\Events\InterviewEvaluated;
use App\Events\InterviewRescheduled;
use App\Events\InterviewScheduled;
use App\Events\TestAssigned;
use App\Events\TestEvaluated;
use App\Events\TestSubmitted;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\EmployerProfile;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Notification;
use App\Models\Test as RecruitmentTest;
use App\Models\TestAttempt;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_user_can_list_count_and_mark_only_own_notifications(): void
    {
        $user = $this->jobSeeker('candidate@example.com');
        $otherUser = $this->jobSeeker('other@example.com');

        $oldNotification = Notification::create([
            'user_id' => $user->id,
            'type' => 'old',
            'title' => 'Old',
            'message' => 'Older notification',
            'created_at' => now()->subDay(),
        ]);

        $newNotification = Notification::create([
            'user_id' => $user->id,
            'type' => 'new',
            'title' => 'New',
            'message' => 'Newer notification',
            'data' => ['job_application_id' => 123],
            'created_at' => now(),
        ]);

        $readNotification = Notification::create([
            'user_id' => $user->id,
            'type' => 'read',
            'title' => 'Read',
            'message' => 'Read notification',
            'read_at' => now(),
        ]);

        Notification::create([
            'user_id' => $otherUser->id,
            'type' => 'private',
            'title' => 'Private',
            'message' => 'Other user notification',
        ]);

        $this->getJson('/api/v1/notifications')
            ->assertUnauthorized();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/notifications?per_page=10')
            ->assertOk()
            ->assertJsonCount(3, 'data.data')
            ->assertJsonPath('data.data.0.id', $readNotification->id)
            ->assertJsonPath('data.data.1.id', $newNotification->id)
            ->assertJsonPath('data.data.2.id', $oldNotification->id)
            ->assertJsonPath('data.data.1.data.job_application_id', 123);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/notifications?is_read=false&type=new&date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $newNotification->id);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->flushHeaders()
            ->withToken($this->tokenFor($otherUser))
            ->patchJson("/api/v1/notifications/{$newNotification->id}/read")
            ->assertNotFound();

        $this->flushHeaders()
            ->withToken($this->tokenFor($user))
            ->patchJson("/api/v1/notifications/{$newNotification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $newNotification->id);

        $this->assertNotNull($newNotification->refresh()->read_at);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_user_can_mark_all_and_delete_only_own_notifications(): void
    {
        $user = $this->jobSeeker('candidate@example.com');
        $otherUser = $this->jobSeeker('other@example.com');

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'application.status_changed',
            'title' => 'Status changed',
            'message' => 'Status changed',
        ]);

        $otherNotification = Notification::create([
            'user_id' => $otherUser->id,
            'type' => 'application.status_changed',
            'title' => 'Other',
            'message' => 'Other',
        ]);

        $this->withToken($this->tokenFor($otherUser))
            ->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertNotFound();

        $this->flushHeaders()
            ->withToken($this->tokenFor($user))
            ->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertNotNull($notification->refresh()->read_at);
        $this->assertNull($otherNotification->refresh()->read_at);

        $this->withToken($this->tokenFor($user))
            ->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
        $this->assertDatabaseHas('notifications', ['id' => $otherNotification->id]);
    }

    public function test_applying_for_job_notifies_candidate_and_company_employers(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/applications", [
                'selected_cv_file_id' => $this->cvFor($candidate)->id,
                'consent_to_share_profile' => true,
            ])
            ->assertCreated();

        $application = JobApplication::query()->firstOrFail();
        event(new ApplicationSubmitted($application->id));
        event(new ApplicationSubmitted($application->id));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'application.submitted',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'application.submitted')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $employer->id)->where('type', 'application.received')->count());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employer->id,
            'type' => 'application.received',
        ]);

    }

    public function test_workflow_events_create_candidate_notifications(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $candidate->jobSeekerProfile, 'under_review');
        $test = $this->test_catalog_entry($company);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'shortlisted',
                'note' => 'Strong profile.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'application.status_changed',
        ]);

        $statusHistory = $application->statusHistory()->reorder()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('event_side_effect_executions', [
            'effect_key' => "application.status_changed:job_application:{$application->id}:occurrence:{$statusHistory->id}:notification:user:{$candidate->id}:v1",
        ]);
        event(new ApplicationStatusChanged($application->id, 'under_review', 'shortlisted', $employer->id, 'Strong profile.', $statusHistory->id));
        event(new ApplicationStatusChanged($application->id, 'under_review', 'shortlisted', $employer->id, 'Strong profile.', $statusHistory->id));
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'application.status_changed')->count());

        $statusNotification = Notification::query()
            ->where('user_id', $candidate->id)
            ->where('type', 'application.status_changed')
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('note', $statusNotification->data);
        $this->assertArrayNotHasKey('changed_by_user_id', $statusNotification->data);

        $assignmentResponse = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", [
                'test_id' => $test->id,
            ])
            ->assertCreated();

        $assignment = ApplicationTestAssignment::findOrFail($assignmentResponse->json('data.id'));

        event(new TestAssigned($assignment->id));
        event(new TestAssigned($assignment->id));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'test.assigned',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'test.assigned')->count());

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated();

        $submitResponse = $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", [
                'confirm' => true,
            ])
            ->assertOk();

        $attempt = TestAttempt::findOrFail($submitResponse->json('data.id'));

        event(new TestSubmitted($attempt->id));
        event(new TestSubmitted($attempt->id));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employer->id,
            'type' => 'test.submitted',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $employer->id)->where('type', 'test.submitted')->count());

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/tests/{$attempt->id}/evaluate", [
                'score' => 88,
                'feedback' => 'Good work.',
            ])
            ->assertOk();

        event(new TestEvaluated($attempt->id));
        event(new TestEvaluated($attempt->id));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'test.evaluated',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'test.evaluated')->count());

        $testNotification = Notification::query()
            ->where('user_id', $candidate->id)
            ->where('type', 'test.evaluated')
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('score', $testNotification->data);

        $interviewResponse = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", [
                'interview_type' => 'technical',
                'scheduled_at' => now()->addDay()->toISOString(),
                'duration_minutes' => 60,
                'interview_mode' => 'video',
                'meeting_link' => 'https://example.test/meeting',
            ])
            ->assertCreated();

        $interview = Interview::findOrFail($interviewResponse->json('data.id'));

        $scheduledHistoryId = $interview->statusHistory()->where('to_status', 'scheduled')->value('id');
        event(new InterviewScheduled($interview->id, $scheduledHistoryId));
        event(new InterviewScheduled($interview->id, $scheduledHistoryId));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'interview.scheduled',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'interview.scheduled')->count());

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interview->id}/reschedule", [
                'mode' => 'online',
                'scheduled_start_at' => now()->addDays(2)->toISOString(),
                'scheduled_end_at' => now()->addDays(2)->addMinutes(45)->toISOString(),
                'meeting_link' => 'https://example.test/meeting-2',
                'reason' => 'Panel availability changed.',
            ])
            ->assertOk();

        $scheduleChangeId = $interview->scheduleChanges()->latest('id')->value('id');
        event(new InterviewRescheduled($interview->id, $scheduleChangeId));
        event(new InterviewRescheduled($interview->id, $scheduleChangeId));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'interview.rescheduled',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'interview.rescheduled')->count());

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/interviews/{$interview->id}/confirm")
            ->assertOk();

        Carbon::setTestNow($interview->fresh()->scheduled_at);
        $this->withToken($this->tokenFor($employer))
            ->putJson("/api/v1/interviews/{$interview->id}/attendance", [
                'candidate_status' => 'present',
                'interviewer_status' => 'present',
            ])->assertOk();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interview->id}/complete", [
                'completion_note' => 'Completed on time.',
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/interviews/{$interview->id}/evaluate", [
                'recommendation' => 'advance',
                'overall_comment' => 'Good fit.',
                'items' => [
                    ['criterion' => 'Technical depth', 'score' => 5],
                ],
            ])
            ->assertOk();

        $evaluatedHistoryId = $interview->statusHistory()->where('to_status', 'evaluated')->value('id');
        event(new InterviewEvaluated($interview->id, $evaluatedHistoryId));
        event(new InterviewEvaluated($interview->id, $evaluatedHistoryId));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'interview.evaluated',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'interview.evaluated')->count());

        $interviewNotification = Notification::query()
            ->where('user_id', $candidate->id)
            ->where('type', 'interview.evaluated')
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('recommendation', $interviewNotification->data);
        $this->assertArrayNotHasKey('evaluated_by_user_id', $interviewNotification->data);
    }

    public function test_need_more_information_and_final_decisions_create_safe_candidate_notifications(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $candidate->jobSeekerProfile, 'under_review');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/information-requests", [
                'message' => 'Please provide a supporting document.',
                'requested_items' => [['label' => 'Supporting document']],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'application.information_requested',
        ]);

        $needMoreInfo = Notification::query()
            ->where('user_id', $candidate->id)
            ->where('type', 'application.information_requested')
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('note', $needMoreInfo->data);

        $informationRequest = ApplicationInformationRequest::query()->where('job_application_id', $application->id)->firstOrFail();
        event(new ApplicationInformationRequested($informationRequest->id));
        event(new ApplicationInformationRequested($informationRequest->id));
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'application.information_requested')->count());
        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/information-requests/{$informationRequest->id}/cancel")
            ->assertOk();

        event(new ApplicationInformationRequestCancelled($informationRequest->id));
        event(new ApplicationInformationRequestCancelled($informationRequest->id));
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'application.information_request_cancelled')->count());

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'rejected',
                'note' => 'Private rejection reason.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'final.rejected',
        ]);

        $finalNotification = Notification::query()
            ->where('user_id', $candidate->id)
            ->where('type', 'final.rejected')
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('note', $finalNotification->data);
        $this->assertStringNotContainsString('Private rejection reason', $finalNotification->message);
    }

    public function test_cancelling_interview_creates_candidate_notification(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $candidate->jobSeekerProfile, 'interview_pending');

        $interviewResponse = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", [
                'interview_type' => 'technical',
                'scheduled_at' => now()->addDay()->toISOString(),
                'duration_minutes' => 60,
                'interview_mode' => 'video',
                'meeting_link' => 'https://example.test/meeting',
            ])
            ->assertCreated();

        $interview = Interview::findOrFail($interviewResponse->json('data.id'));
        $scheduledAt = $interview->scheduled_at?->toISOString();

        $this->withToken($this->tokenFor($employer))
            ->deleteJson("/api/v1/interviews/{$interview->id}")
            ->assertOk();

        $cancelledHistoryId = $interview->statusHistory()->where('to_status', 'cancelled')->value('id');
        event(new InterviewCancelled($application->id, $interview->id, $scheduledAt, $cancelledHistoryId));
        event(new InterviewCancelled($application->id, $interview->id, $scheduledAt, $cancelledHistoryId));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'interview.cancelled',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'interview.cancelled')->count());
    }

    public function test_final_accept_creates_candidate_notification(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $candidate->jobSeekerProfile, 'final_review');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'accepted',
                'note' => 'Private acceptance note.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'final.accepted',
        ]);
    }

    public function test_failed_workflow_validation_does_not_create_notification(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $candidate->jobSeekerProfile, 'accepted');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'rejected',
            ])
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('notifications', 0);
    }

    private function employer(string $email, Company $company): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::EMPLOYER,
        ]);

        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return $user->load('employerProfile.company');
    }

    private function jobSeeker(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::JOB_SEEKER,
        ]);

        JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => 'Backend Developer',
        ]);

        return $user->load('jobSeekerProfile');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function jobPostingFor(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Platform Engineer',
            'description' => 'Build smart recruitment APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'salary_min' => 70000,
            'salary_max' => 90000,
            'status' => 'draft',
            'published_at' => null,
        ], $overrides));
    }

    private function applicationFor(JobPosting $jobPosting, JobSeekerProfile $profile, string $statusSlug): JobApplication
    {
        $statusId = ApplicationStatus::query()->where('slug', $statusSlug)->value('id');

        $application = JobApplication::create([
            'job_posting_id' => $jobPosting->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => $statusId,
        ]);

        $application->statusHistory()->create([
            'from_application_status_id' => null,
            'to_application_status_id' => $statusId,
            'changed_by_user_id' => $profile->user_id,
        ]);

        return $application->load('applicationStatus', 'jobPosting', 'jobSeekerProfile');
    }

    private function cvFor(User $jobSeeker): CVFile
    {
        $cvFile = CVFile::create([
            'user_id' => $jobSeeker->id,
            'original_name' => 'backend-developer-cv.pdf',
            'stored_path' => 'cv-files/backend-developer-cv.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 128000,
            'status' => 'parsed',
        ]);
        Storage::disk('local')->put($cvFile->stored_path, 'cv');

        return $cvFile;
    }

    private function test_catalog_entry(?Company $company = null): RecruitmentTest
    {
        $company ??= Company::create(['name' => 'Notification Test Co.', 'approval_status' => 'approved']);

        $test = RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => 'Backend Assessment',
            'duration_minutes' => 60,
            'max_score' => 100,
            'passing_score' => 70,
            'is_active' => true,
        ]);
        $test->questions()->create([
            'question_text' => 'Notification scoreable question',
            'question_type' => 'short_text',
            'order_index' => 999,
            'points' => 100,
            'is_required' => false,
        ]);

        return $test;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
