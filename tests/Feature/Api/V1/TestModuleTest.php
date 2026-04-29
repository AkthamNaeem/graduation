<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Test as RecruitmentTest;
use App\Models\TestAttempt;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_employer_can_assign_a_test_and_application_moves_to_test_pending(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
        $employer = $this->employer('owner@example.com', $company);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile, 'under_review');
        $test = $this->testCatalogEntry();

        $response = $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", [
                'test_id' => $test->id,
                'note' => 'Please complete the backend assessment.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.job_application_id', $application->id)
            ->assertJsonPath('data.test_id', $test->id)
            ->assertJsonPath('data.state', 'not_started');

        $assignmentId = $response->json('data.id');

        $this->assertDatabaseHas('application_test_assignments', [
            'id' => $assignmentId,
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'test_pending')->value('id'),
        ]);
    }

    public function test_unauthorized_employer_cannot_assign_list_or_evaluate_tests(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
        $ownerEmployer = $this->employer('owner@example.com', $company);
        $otherEmployer = $this->employer('other@example.com');
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile, 'under_review');
        $test = $this->testCatalogEntry();

        $this->withToken($this->tokenFor($otherEmployer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", [
                'test_id' => $test->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $assignment = $this->assignTest($ownerEmployer, $application, $test);
        $attempt = $this->startAttempt($assignment);
        $this->submitAttempt($attempt, ['q1' => 'answer']);

        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/applications/{$application->id}/tests")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($otherEmployer))
            ->postJson("/api/v1/tests/{$attempt->id}/evaluate", [
                'score' => 75,
                'feedback' => 'Good effort.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_candidate_sees_only_own_assigned_tests(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
        $employer = $this->employer('owner@example.com', $company);
        $firstSeeker = $this->jobSeeker('first@example.com');
        $secondSeeker = $this->jobSeeker('second@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $firstApplication = $this->applicationFor($jobPosting, $firstSeeker->jobSeekerProfile, 'under_review');
        $secondApplication = $this->applicationFor($jobPosting, $secondSeeker->jobSeekerProfile, 'under_review');
        $firstTest = $this->testCatalogEntry('Backend Assessment');
        $secondTest = $this->testCatalogEntry('Frontend Assessment');

        $firstAssignment = $this->assignTest($employer, $firstApplication, $firstTest);
        $this->assignTest($employer, $secondApplication, $secondTest);

        $this->withToken($this->tokenFor($firstSeeker))
            ->getJson('/api/v1/my/tests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstAssignment->id)
            ->assertJsonPath('data.0.test.title', 'Backend Assessment')
            ->assertJsonPath('data.0.job_application.id', $firstApplication->id);
    }

    public function test_candidate_can_start_exactly_one_attempt(): void
    {
        $assignment = $this->assignedTestScenario();

        $this->withToken($this->tokenFor($assignment->jobApplication->jobSeekerProfile->user))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated()
            ->assertJsonPath('data.application_test_assignment_id', $assignment->id)
            ->assertJsonPath('data.submitted_at', null);

        $this->assertDatabaseHas('test_attempts', [
            'application_test_assignment_id' => $assignment->id,
        ]);

        $this->withToken($this->tokenFor($assignment->jobApplication->jobSeekerProfile->user))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignment_id']);
    }

    public function test_candidate_can_submit_only_own_active_attempt_and_cannot_resubmit(): void
    {
        $assignment = $this->assignedTestScenario();
        $candidate = $assignment->jobApplication->jobSeekerProfile->user;
        $otherCandidate = $this->jobSeeker('other-candidate@example.com');

        $this->withToken($this->tokenFor($otherCandidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", [
                'answers' => ['q1' => 'hack'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", [
                'answers' => ['q1' => 'answer'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignment_id']);

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated();

        $this->withToken($this->tokenFor($otherCandidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", [
                'answers' => [
                    'q1' => 'Dependency injection',
                    'q2' => ['transactions', 'queues'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.answers.q1', 'Dependency injection')
            ->assertJsonPath('data.submitted_at', fn (mixed $value) => is_string($value) && $value !== '');

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", [
                'answers' => ['q1' => 'second try'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignment_id']);
    }

    public function test_employer_can_evaluate_submitted_attempt_and_application_moves_to_test_completed(): void
    {
        [$employer, $application, $attempt] = $this->submittedAttemptScenario();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/tests/{$attempt->id}/evaluate", [
                'score' => 82.5,
                'feedback' => 'Strong API design and validation choices.',
            ])
            ->assertOk()
            ->assertJsonPath('data.score', '82.50')
            ->assertJsonPath('data.feedback', 'Strong API design and validation choices.');

        $this->assertDatabaseHas('test_attempts', [
            'id' => $attempt->id,
            'evaluated_by_user_id' => $employer->id,
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'test_completed')->value('id'),
        ]);

        $this->assertFalse(Schema::hasColumn('job_applications', 'score'));
    }

    public function test_employer_cannot_evaluate_before_submission_or_re_evaluate(): void
    {
        $assignment = $this->assignedTestScenario();
        $candidate = $assignment->jobApplication->jobSeekerProfile->user;
        $employer = $assignment->assignedBy;

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated();

        $attempt = TestAttempt::query()->where('application_test_assignment_id', $assignment->id)->firstOrFail();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/tests/{$attempt->id}/evaluate", [
                'score' => 60,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attempt_id']);

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", [
                'answers' => ['q1' => 'done'],
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/tests/{$attempt->id}/evaluate", [
                'score' => 60,
                'feedback' => 'Accepted.',
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/tests/{$attempt->id}/evaluate", [
                'score' => 61,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attempt_id']);
    }

    private function assignedTestScenario(): ApplicationTestAssignment
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
        $employer = $this->employer('owner@example.com', $company);
        $jobSeeker = $this->jobSeeker('candidate@example.com');
        $jobPosting = $this->jobPostingFor($company, ['status' => 'open', 'published_at' => now()->subHour()]);
        $application = $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile, 'under_review');
        $test = $this->testCatalogEntry();

        return $this->assignTest($employer, $application, $test);
    }

    /**
     * @return array{0: User, 1: JobApplication, 2: TestAttempt}
     */
    private function submittedAttemptScenario(): array
    {
        $assignment = $this->assignedTestScenario();
        $candidate = $assignment->jobApplication->jobSeekerProfile->user;

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/start")
            ->assertCreated();

        $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", [
                'answers' => ['q1' => 'finished'],
            ])
            ->assertOk();

        $attempt = TestAttempt::query()->where('application_test_assignment_id', $assignment->id)->firstOrFail();

        return [$assignment->assignedBy, $assignment->jobApplication, $attempt];
    }

    private function assignTest(User $employer, JobApplication $application, RecruitmentTest $test): ApplicationTestAssignment
    {
        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/assign-test", [
                'test_id' => $test->id,
            ])
            ->assertCreated();

        return ApplicationTestAssignment::query()
            ->with(['jobApplication.jobSeekerProfile.user', 'assignedBy', 'test'])
            ->where('job_application_id', $application->id)
            ->where('test_id', $test->id)
            ->firstOrFail();
    }

    /**
     * @param  array<int|string, mixed>  $answers
     */
    private function submitAttempt(TestAttempt $attempt, array $answers): void
    {
        $attempt->forceFill([
            'answers' => $answers,
            'submitted_at' => now(),
        ])->save();
    }

    private function startAttempt(ApplicationTestAssignment $assignment): TestAttempt
    {
        return TestAttempt::create([
            'application_test_assignment_id' => $assignment->id,
            'started_at' => now(),
        ]);
    }

    private function testCatalogEntry(string $title = 'Laravel Assessment'): RecruitmentTest
    {
        return RecruitmentTest::create([
            'title' => $title,
            'description' => 'Reusable technical assessment.',
            'instructions' => 'Answer all questions.',
            'duration_minutes' => 90,
            'max_score' => 100,
            'passing_score' => 70,
            'is_active' => true,
        ]);
    }

    private function employer(string $email = 'employer@example.com', ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Acme Hiring Co. '.$email]);

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

        return $application->load('applicationStatus', 'jobPosting', 'jobSeekerProfile.user');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
