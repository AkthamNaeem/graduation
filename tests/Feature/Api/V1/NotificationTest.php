<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.id', $newNotification->id)
            ->assertJsonPath('data.data.1.id', $oldNotification->id)
            ->assertJsonPath('data.data.0.data.job_application_id', 123);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->flushHeaders()
            ->withToken($this->tokenFor($otherUser))
            ->postJson("/api/v1/notifications/{$newNotification->id}/read")
            ->assertNotFound();

        $this->flushHeaders()
            ->withToken($this->tokenFor($user))
            ->postJson("/api/v1/notifications/{$newNotification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $newNotification->id);

        $this->assertNotNull($newNotification->refresh()->read_at);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_workflow_events_create_candidate_notifications(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $candidate->jobSeekerProfile, 'under_review');
        $test = $this->testCatalogEntry();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'shortlisted',
                'note' => 'Strong profile.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'application_status_changed',
        ]);

        $assignmentResponse = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", [
                'test_id' => $test->id,
            ])
            ->assertCreated();

        $assignment = ApplicationTestAssignment::findOrFail($assignmentResponse->json('data.id'));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'test_assigned',
        ]);

        $attempt = TestAttempt::create([
            'application_test_assignment_id' => $assignment->id,
            'answers' => ['q1' => 'a1'],
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/tests/{$attempt->id}/evaluate", [
                'score' => 88,
                'feedback' => 'Good work.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'test_evaluated',
        ]);

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

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'interview_scheduled',
        ]);

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

        $this->assertDatabaseHas('notifications', [
            'user_id' => $candidate->id,
            'type' => 'interview_evaluated',
        ]);
    }

    public function test_failed_workflow_validation_does_not_create_notification(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
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

    private function testCatalogEntry(): RecruitmentTest
    {
        return RecruitmentTest::create([
            'title' => 'Backend Assessment',
            'duration_minutes' => 60,
            'max_score' => 100,
            'passing_score' => 70,
            'is_active' => true,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
