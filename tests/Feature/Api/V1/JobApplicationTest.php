<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\CVFile;
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

    public function test_job_seeker_can_apply_to_open_job_with_selected_cv(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker();
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()]);
        $payload = $this->applicationPayload($jobSeeker);

        $response = $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/applications", $payload)
            ->assertCreated()
            ->assertJsonPath('data.job_posting_id', $jobPosting->id)
            ->assertJsonPath('data.job_seeker_profile_id', $jobSeeker->jobSeekerProfile->id)
            ->assertJsonPath('data.selected_cv_file_id', $payload['selected_cv_file_id'])
            ->assertJsonPath('data.status.slug', 'submitted');

        $this->assertDatabaseHas('job_applications', [
            'id' => $response->json('data.id'),
            'selected_cv_file_id' => $payload['selected_cv_file_id'],
            'consent_to_share_profile' => true,
        ]);

        $this->assertDatabaseHas('application_status_histories', [
            'job_application_id' => $response->json('data.id'),
            'changed_by_user_id' => $jobSeeker->id,
        ]);
    }

    public function test_apply_requires_selected_cv_and_consent(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker();
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()]);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$jobPosting->id}", ['consent_to_share_profile' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['selected_cv_file_id']);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$jobPosting->id}", [
                'selected_cv_file_id' => $this->cvFor($jobSeeker)->id,
                'consent_to_share_profile' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_to_share_profile']);
    }

    public function test_apply_rejects_cv_owned_by_another_user(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker('owner@example.com');
        $otherSeeker = $this->jobSeeker('other@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()]);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$jobPosting->id}", [
                'selected_cv_file_id' => $this->cvFor($otherSeeker)->id,
                'consent_to_share_profile' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['selected_cv_file_id']);

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_duplicate_applications_and_non_open_jobs_are_blocked(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker();
        $openJob = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()]);
        $closedJob = $this->jobPostingFor($company, ['status' => 'closed', 'published_at' => now()]);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$openJob->id}", $this->applicationPayload($jobSeeker))
            ->assertCreated();

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$openJob->id}", $this->applicationPayload($jobSeeker))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_posting_id']);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$closedJob->id}", $this->applicationPayload($jobSeeker))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_posting_id']);
    }

    public function test_employer_can_change_status_and_history_is_appended(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/status", [
                'status' => 'under_review',
                'note' => 'Profile looks strong.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'under_review')
            ->assertJsonCount(2, 'data.status_history');
    }

    public function test_job_seeker_can_withdraw_active_application(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile, 'under_review');

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson("/api/v1/applications/{$application->id}/withdraw", ['note' => 'Accepted another offer.'])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'withdrawn');
    }

    private function employer(string $email = 'employer@example.com', ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Acme Hiring Co. '.$email, 'approval_status' => 'approved']);
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    private function jobSeeker(string $email = 'jobseeker@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $user->id, 'headline' => 'Backend Developer']);

        return $user->load('jobSeekerProfile');
    }

    private function jobPostingFor(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Platform Engineer',
            'description' => 'Build smart recruitment APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'draft',
            'published_at' => null,
        ], $overrides));
    }

    private function applicationFor(JobPosting $jobPosting, JobSeekerProfile $profile, string $statusSlug = 'submitted'): JobApplication
    {
        $statusId = ApplicationStatus::query()->where('slug', $statusSlug)->value('id');

        $application = JobApplication::create([
            'job_posting_id' => $jobPosting->id,
            'job_seeker_profile_id' => $profile->id,
            'selected_cv_file_id' => $this->cvFor($profile->user)->id,
            'application_status_id' => $statusId,
            'consent_to_share_profile' => true,
        ]);

        $application->statusHistory()->create([
            'from_application_status_id' => null,
            'to_application_status_id' => $statusId,
            'changed_by_user_id' => $profile->user_id,
        ]);

        return $application->load('applicationStatus', 'jobPosting', 'jobSeekerProfile');
    }

    private function applicationPayload(User $jobSeeker): array
    {
        return [
            'selected_cv_file_id' => $this->cvFor($jobSeeker)->id,
            'consent_to_share_profile' => true,
        ];
    }

    private function cvFor(User $jobSeeker): CVFile
    {
        return CVFile::create([
            'user_id' => $jobSeeker->id,
            'original_name' => 'backend-developer-cv.pdf',
            'stored_path' => 'cv-files/backend-developer-cv.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 128000,
            'status' => 'parsed',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
