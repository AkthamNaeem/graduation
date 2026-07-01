<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_application_status_seeder_creates_all_expected_statuses(): void
    {
        $expectedSlugs = [
            'submitted',
            'under_review',
            'shortlisted',
            'test_pending',
            'test_completed',
            'interview_pending',
            'interview_scheduled',
            'interview_completed',
            'final_review',
            'accepted',
            'rejected',
            'withdrawn',
            'on_hold',
            'need_more_information',
        ];

        $this->assertDatabaseCount('application_statuses', 14);
        $this->assertSame($expectedSlugs, ApplicationStatus::query()->orderBy('id')->pluck('slug')->all());
    }

    public function test_job_seeker_can_apply_to_open_job_and_history_is_recorded(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker();
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);

        $response = $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$jobPosting->id}")
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_posting_id', $jobPosting->id)
            ->assertJsonPath('data.job_seeker_profile_id', $jobSeeker->jobSeekerProfile->id)
            ->assertJsonPath('data.status.slug', 'submitted')
            ->assertJsonCount(1, 'data.status_history');

        $applicationId = $response->json('data.id');

        $this->assertDatabaseHas('job_applications', [
            'id' => $applicationId,
            'job_posting_id' => $jobPosting->id,
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'submitted')->value('id'),
        ]);

        $this->assertDatabaseHas('application_status_histories', [
            'job_application_id' => $applicationId,
            'from_application_status_id' => null,
            'to_application_status_id' => ApplicationStatus::query()->where('slug', 'submitted')->value('id'),
            'changed_by_user_id' => $jobSeeker->id,
        ]);
    }

    public function test_job_seeker_can_apply_using_clear_job_applications_route(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker();
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/applications")
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_posting_id', $jobPosting->id)
            ->assertJsonPath('data.status.slug', 'submitted');

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$jobPosting->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_posting_id']);

        $this->assertDatabaseCount('job_applications', 1);
    }

    public function test_duplicate_applications_are_blocked(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker();
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$jobPosting->id}")
            ->assertCreated();

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$jobPosting->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['job_posting_id']);

        $this->assertDatabaseCount('job_applications', 1);
    }

    public function test_application_visibility_is_limited_to_applicant_and_owning_employer(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $ownerEmployer = $this->employer('owner@example.com', $company);
        $otherEmployer = $this->employer('other@example.com');
        $jobSeeker = $this->jobSeeker('seeker@example.com');
        $otherSeeker = $this->jobSeeker('other-seeker@example.com');
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile);

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id);

        $this->withToken($this->tokenFor($ownerEmployer))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id);

        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($otherSeeker))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_job_seeker_can_list_own_applications_only(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker('owner-seeker@example.com');
        $otherSeeker = $this->jobSeeker('other-seeker@example.com');
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $otherJobPosting = $this->jobPostingFor($company, [
            'title' => 'Another Role',
            'status' => 'open',
            'published_at' => now()->subMinutes(30),
        ]);

        $ownedApplication = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile);
        $this->applicationFor($otherJobPosting, $otherSeeker->jobSeekerProfile);

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson('/api/v1/applications/my')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ownedApplication->id)
            ->assertJsonPath('data.meta.current_page', 1);
    }

    public function test_employer_can_list_applications_for_owned_job_only(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $ownerEmployer = $this->employer('owner@example.com', $company);
        $otherEmployer = $this->employer('other@example.com');
        $firstSeeker = $this->jobSeeker('first@example.com');
        $secondSeeker = $this->jobSeeker('second@example.com');
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);

        $this->applicationFor($jobPosting, $firstSeeker->jobSeekerProfile);
        $this->applicationFor($jobPosting, $secondSeeker->jobSeekerProfile, 'under_review');

        $this->withToken($this->tokenFor($ownerEmployer))
            ->getJson("/api/v1/jobs/{$jobPosting->id}/applications")
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.meta.current_page', 1);

        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/jobs/{$jobPosting->id}/applications")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_employer_can_change_status_using_valid_transitions_and_history_is_appended(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'under_review',
                'note' => 'Profile looks strong.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'under_review')
            ->assertJsonCount(2, 'data.status_history');

        $application->refresh();

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'under_review')->value('id'),
        ]);

        $this->assertDatabaseHas('application_status_histories', [
            'job_application_id' => $application->id,
            'from_application_status_id' => ApplicationStatus::query()->where('slug', 'submitted')->value('id'),
            'to_application_status_id' => ApplicationStatus::query()->where('slug', 'under_review')->value('id'),
            'changed_by_user_id' => $employer->id,
            'note' => 'Profile looks strong.',
        ]);
    }

    public function test_final_application_accept_and_reject_create_audit_logs(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $acceptedApplication = $this->applicationFor($jobPosting, $this->jobSeeker('accepted@example.com')->jobSeekerProfile, 'final_review');
        $rejectedApplication = $this->applicationFor($jobPosting, $this->jobSeeker('rejected@example.com')->jobSeekerProfile, 'final_review');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$acceptedApplication->id}/status", [
                'status' => 'accepted',
                'note' => 'Offer approved.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'accepted');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$rejectedApplication->id}/status", [
                'status' => 'rejected',
                'note' => 'Not selected.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'rejected');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'application.accepted',
            'entity_type' => JobApplication::class,
            'entity_id' => $acceptedApplication->id,
            'actor_user_id' => $employer->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'application.rejected',
            'entity_type' => JobApplication::class,
            'entity_id' => $rejectedApplication->id,
            'actor_user_id' => $employer->id,
        ]);
    }

    public function test_invalid_transitions_and_terminal_states_are_blocked(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'accepted',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $terminalApplication = $this->applicationFor($jobPosting, $this->jobSeeker('terminal@example.com')->jobSeekerProfile, 'accepted');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$terminalApplication->id}/status", [
                'status' => 'rejected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('job_applications', [
            'id' => $terminalApplication->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'accepted')->value('id'),
        ]);
    }

    public function test_job_seeker_can_withdraw_active_application_and_employer_cannot_withdraw_or_force_withdraw_status(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile, 'under_review');

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$application->id}/withdraw", [
                'note' => 'Accepted another offer.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'withdrawn');

        $this->assertDatabaseHas('application_status_histories', [
            'job_application_id' => $application->id,
            'from_application_status_id' => ApplicationStatus::query()->where('slug', 'under_review')->value('id'),
            'to_application_status_id' => ApplicationStatus::query()->where('slug', 'withdrawn')->value('id'),
            'changed_by_user_id' => $jobSeeker->id,
            'note' => 'Accepted another offer.',
        ]);

        $secondApplication = $this->applicationFor($jobPosting, $this->jobSeeker('second@example.com')->jobSeekerProfile, 'under_review');

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$secondApplication->id}/withdraw")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$secondApplication->id}/status", [
                'status' => 'withdrawn',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_applications_to_non_open_jobs_are_rejected_and_seekers_cannot_change_status(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $draftJob = $this->jobPostingFor($company, ['status' => 'draft', 'published_at' => null]);
        $closedJob = $this->jobPostingFor($company, [
            'title' => 'Closed Role',
            'status' => 'closed',
            'published_at' => now()->subDay(),
        ]);
        $openJob = $this->jobPostingFor($company, [
            'title' => 'Open Role',
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $application = $this->applicationFor($openJob, $jobSeeker->jobSeekerProfile);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$draftJob->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_posting_id']);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$closedJob->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_posting_id']);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'under_review',
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    private function employer(string $email = 'employer@example.com', ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Acme Hiring Co. '.$email, 'approval_status' => 'approved']);

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

    private function jobSeeker(string $email = 'jobseeker@example.com'): User
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

    private function applicationFor(
        JobPosting $jobPosting,
        JobSeekerProfile $jobSeekerProfile,
        string $statusSlug = 'submitted',
    ): JobApplication {
        $statusId = ApplicationStatus::query()->where('slug', $statusSlug)->value('id');

        $application = JobApplication::create([
            'job_posting_id' => $jobPosting->id,
            'job_seeker_profile_id' => $jobSeekerProfile->id,
            'application_status_id' => $statusId,
        ]);

        $application->statusHistory()->create([
            'from_application_status_id' => null,
            'to_application_status_id' => $statusId,
            'changed_by_user_id' => $jobSeekerProfile->user_id,
            'note' => null,
        ]);

        return $application->load('applicationStatus', 'jobPosting', 'jobSeekerProfile');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
