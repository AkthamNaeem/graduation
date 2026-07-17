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
use App\Models\TestAttempt;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_company_state_codes_block_recruitment_but_keep_identity_access_and_tokens(): void
    {
        $expectedCodes = [
            'pending' => 'COMPANY_PENDING',
            'rejected' => 'COMPANY_REJECTED',
            'suspended' => 'COMPANY_SUSPENDED',
        ];

        foreach ($expectedCodes as $status => $code) {
            $company = Company::create(['name' => ucfirst($status).' Company', 'approval_status' => $status]);
            $employer = $this->employer($company, "{$status}@example.com");
            $token = $this->tokenFor($employer);
            $this->app['auth']->forgetGuards();

            $this->withToken($token)->getJson('/api/v1/auth/me')
                ->assertOk()->assertJsonPath('data.employer_profile.company.approval_status', $status);
            $this->withToken($token)->getJson('/api/v1/company')
                ->assertOk()->assertJsonPath('data.approval_status', $status);
            $this->withToken($token)->putJson('/api/v1/company', ['description' => 'Corrected company profile.'])
                ->assertOk()->assertJsonPath('data.approval_status', $status);
            $this->withToken($token)->postJson('/api/v1/jobs', [])
                ->assertForbidden()->assertJsonPath('code', $code);

            $this->assertSame(1, $employer->tokens()->count());
        }
    }

    public function test_public_jobs_only_show_approved_companies_and_reapproval_restores_visibility_without_mutation(): void
    {
        $jobs = [];
        foreach (['approved', 'pending', 'rejected', 'suspended'] as $status) {
            $company = Company::create(['name' => ucfirst($status).' Co', 'approval_status' => $status]);
            $jobs[$status] = $this->job($company, ucfirst($status).' Engineer');
        }

        $this->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $jobs['approved']->id);
        $this->getJson("/api/v1/jobs/{$jobs['suspended']->id}")->assertNotFound();
        $this->getJson('/api/v1/jobs?search=Engineer&location=Remote')
            ->assertOk()->assertJsonCount(1, 'data.data');

        $suspendedCompany = $jobs['suspended']->company;
        $employer = $this->employer($suspendedCompany, 'reapproved@example.com');
        $employerToken = $this->tokenFor($employer);
        $this->withToken($employerToken)->postJson('/api/v1/jobs', [])->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/companies/{$suspendedCompany->id}/approve")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($employerToken)->postJson('/api/v1/jobs', [
            'title' => 'Reapproved Role',
            'description' => 'Recruitment resumed without replacing the employer token.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'work_mode' => 'remote',
        ])->assertCreated();
        $this->getJson("/api/v1/jobs/{$jobs['suspended']->id}")->assertOk();
        $this->assertSame(1, $employer->tokens()->count());
        $this->assertSame('open', $jobs['suspended']->refresh()->status);
        $this->assertNotNull($jobs['suspended']->published_at);
    }

    public function test_candidate_mutations_freeze_while_historical_reads_and_withdraw_remain_available(): void
    {
        $company = Company::create(['name' => 'Frozen Recruitment', 'approval_status' => 'approved']);
        $employer = $this->employer($company, 'frozen-employer@example.com');
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = $this->job($company, 'Frozen Role');
        $status = ApplicationStatus::query()->where('slug', 'test_pending')->firstOrFail();
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => $status->id,
            'consent_to_share_profile' => true,
        ]);
        $test = RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => 'Frozen Test',
            'duration_minutes' => 60,
            'max_score' => 100,
            'is_active' => true,
        ]);
        $question = $test->questions()->create([
            'question_text' => 'Explain state guards.',
            'question_type' => 'short_text',
            'order_index' => 1,
            'points' => 10,
            'is_required' => true,
        ]);
        $assignment = ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
            'assigned_at' => now(),
        ]);
        $token = $this->tokenFor($candidate);

        $company->forceFill(['approval_status' => 'suspended'])->save();

        $this->withToken($token)->getJson('/api/v1/applications/my')->assertOk();
        $this->withToken($token)->getJson('/api/v1/my/tests')->assertOk();
        $this->withToken($token)->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertForbidden()->assertJsonPath('code', 'COMPANY_RECRUITMENT_UNAVAILABLE');

        $attempt = TestAttempt::create([
            'application_test_assignment_id' => $assignment->id,
            'started_at' => now(),
        ]);
        $this->withToken($token)->getJson("/api/v1/test-attempts/{$attempt->id}/answers")->assertOk();
        $this->withToken($token)->putJson("/api/v1/test-attempts/{$attempt->id}/answers/{$question->id}", [
            'answer_text' => 'Blocked write',
        ])->assertForbidden()->assertJsonPath('code', 'COMPANY_RECRUITMENT_UNAVAILABLE');
        $this->withToken($token)->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true])
            ->assertForbidden()->assertJsonPath('code', 'COMPANY_RECRUITMENT_UNAVAILABLE');

        $this->withToken($token)->postJson("/api/v1/applications/{$application->id}/withdraw")
            ->assertOk()->assertJsonPath('data.status.slug', 'withdrawn');
        $this->assertDatabaseCount('test_attempts', 1);
        $this->assertDatabaseCount('test_answers', 0);
    }

    public function test_candidate_cannot_apply_to_non_approved_company_without_side_effects(): void
    {
        $company = Company::create(['name' => 'Unavailable Company', 'approval_status' => 'pending']);
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = $this->job($company, 'Unavailable Role');
        $cv = CVFile::create([
            'user_id' => $candidate->id,
            'original_name' => 'candidate.pdf',
            'stored_path' => 'cv-files/candidate.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1000,
            'status' => 'parsed',
        ]);

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/jobs/{$job->id}/applications", [
                'selected_cv_file_id' => $cv->id,
                'consent_to_share_profile' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_RECRUITMENT_UNAVAILABLE');

        $this->assertDatabaseCount('job_applications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_representative_recruitment_routes_include_company_guard(): void
    {
        $routeNames = [
            'v1.jobs.store',
            'v1.jobs.applications.index',
            'v1.tests.store',
            'v1.tests.questions.store',
            'v1.applications.tests.assign',
            'v1.test-attempts.answers.grading.update',
            'v1.test-assignments.deadline.update',
            'v1.test-assignments.retake.grant',
            'v1.applications.interviews.store',
        ];

        foreach ($routeNames as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} must exist.");
            $this->assertContains('company.approved', $route->gatherMiddleware(), "Route {$name} must enforce company approval.");
        }
    }

    public function test_missing_employer_company_is_explicit_and_admin_does_not_need_employer_profile(): void
    {
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        $this->withToken($this->tokenFor($employer))->postJson('/api/v1/jobs', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_PROFILE_MISSING');

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($admin))->getJson('/api/v1/tests')
            ->assertOk();
    }

    private function employer(Company $company, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    private function job(Company $company, string $title): JobPosting
    {
        return JobPosting::create([
            'company_id' => $company->id,
            'title' => $title,
            'description' => 'A state-aware recruitment role.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'open',
            'published_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
