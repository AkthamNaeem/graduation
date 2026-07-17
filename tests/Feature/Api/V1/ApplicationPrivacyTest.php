<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Test as RecruitmentTest;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_candidate_application_list_and_details_expose_only_safe_timeline_and_own_data(): void
    {
        [$employer, $candidate, $application] = $this->scenario();
        $otherCandidate = $this->jobSeeker('other-candidate@example.com');

        $this->assertDatabaseHas('application_status_histories', [
            'job_application_id' => $application->id,
            'changed_by_user_id' => $employer->id,
            'note' => 'Internal hiring assessment.',
        ]);

        $list = $this->withToken($this->tokenFor($candidate))
            ->getJson('/api/v1/applications/my')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $application->id)
            ->assertJsonPath('data.data.0.status.slug', 'under_review')
            ->assertJsonPath('data.data.0.cover_letter', 'Candidate cover letter')
            ->assertJsonPath('data.data.0.screening_answers.availability', 'immediate')
            ->assertJsonPath('data.data.0.selected_cv.original_name', 'candidate.pdf');

        $this->assertSafeApplication($list, 'data.data.0');

        $details = $this->withToken($this->tokenFor($candidate))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonPath('data.status_history.1.to_status.slug', 'under_review');

        $this->assertSafeApplication($details, 'data');

        $this->withToken($this->tokenFor($otherCandidate))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertForbidden();
    }

    public function test_owner_employer_keeps_internal_application_history_and_other_company_is_denied(): void
    {
        [$employer, , $application] = $this->scenario();
        $otherEmployer = $this->employer('other-owner@example.com');

        $this->withToken($this->tokenFor($employer))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.status_history.1.note', 'Internal hiring assessment.')
            ->assertJsonPath('data.status_history.1.changed_by.id', $employer->id)
            ->assertJsonPath('data.status_history.1.changed_by.role', 'employer')
            ->assertJsonMissingPath('data.status_history.1.changed_by.email');

        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertForbidden();
    }

    public function test_candidate_nested_application_inside_test_assignment_keeps_safe_boundary(): void
    {
        [$employer, $candidate, $application] = $this->scenario();
        $test = RecruitmentTest::create([
            'title' => 'Privacy Boundary Test',
            'duration_minutes' => 30,
            'max_score' => 10,
            'passing_score' => 5,
            'is_active' => true,
        ]);
        $test->company()->associate($application->jobPosting->company_id)->save();
        ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
            'attempt_number' => 1,
            'max_attempts' => 1,
            'assigned_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($candidate))
            ->getJson('/api/v1/my/tests')
            ->assertOk()
            ->assertJsonPath('data.data.0.job_application.id', $application->id);

        $this->assertSafeApplication($response, 'data.data.0.job_application');
        $response
            ->assertJsonMissingPath('data.data.0.assigned_by_user_id')
            ->assertJsonMissingPath('data.data.0.retake_reason')
            ->assertJsonMissingPath('data.data.0.retake_granted_by_user_id');
    }

    private function assertSafeApplication($response, string $prefix): void
    {
        foreach ([
            'job_seeker_profile',
            'selected_cv.stored_path',
            'selected_cv.disk',
            'status_history.0.note',
            'status_history.0.changed_by_user_id',
            'status_history.0.changed_by',
            'status_history.1.note',
            'status_history.1.changed_by_user_id',
            'status_history.1.changed_by',
            'interviews',
            'evaluation',
            'internal_score',
        ] as $path) {
            $response->assertJsonMissingPath("{$prefix}.{$path}");
        }
    }

    /** @return array{User, User, JobApplication} */
    private function scenario(): array
    {
        $company = Company::create(['name' => 'Privacy Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('owner@example.com', $company);
        $candidate = $this->jobSeeker('candidate@example.com');
        $job = $this->jobPosting($company);
        $submitted = ApplicationStatus::query()->where('slug', 'submitted')->firstOrFail();
        $underReview = ApplicationStatus::query()->where('slug', 'under_review')->firstOrFail();
        $cv = CVFile::create([
            'user_id' => $candidate->id,
            'original_name' => 'candidate.pdf',
            'stored_path' => 'cv-files/private/candidate.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1234,
            'status' => 'parsed',
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $candidate->jobSeekerProfile->id,
            'selected_cv_file_id' => $cv->id,
            'application_status_id' => $underReview->id,
            'cover_letter' => 'Candidate cover letter',
            'consent_to_share_profile' => true,
            'screening_answers' => ['availability' => 'immediate'],
        ]);
        $application->statusHistory()->create([
            'from_application_status_id' => null,
            'to_application_status_id' => $submitted->id,
            'changed_by_user_id' => $candidate->id,
            'note' => 'Candidate withdrawal-style private text.',
        ]);
        $application->statusHistory()->create([
            'from_application_status_id' => $submitted->id,
            'to_application_status_id' => $underReview->id,
            'changed_by_user_id' => $employer->id,
            'note' => 'Internal hiring assessment.',
        ]);

        return [$employer, $candidate, $application];
    }

    private function employer(string $email, ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Company '.$email, 'approval_status' => 'approved']);
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile');
    }

    private function jobSeeker(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $user->id, 'headline' => 'Engineer']);

        return $user->load('jobSeekerProfile');
    }

    private function jobPosting(Company $company): JobPosting
    {
        return JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Engineer',
            'description' => 'Build APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'status' => 'open',
            'published_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
