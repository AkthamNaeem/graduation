<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Test as RecruitmentTest;
use App\Models\TestOption;
use App\Models\TestQuestion;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestCatalogPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_guest_and_candidate_cannot_use_catalog_even_when_assigned_and_started(): void
    {
        [$company, $employer, $candidate, $application] = $this->context();
        $test = $this->testWithQuestion($company);

        $this->getJson('/api/v1/tests')->assertUnauthorized();
        $this->getJson("/api/v1/tests/{$test->id}")->assertUnauthorized();

        foreach (['/api/v1/tests', "/api/v1/tests/{$test->id}"] as $url) {
            $this->withToken($this->token($candidate))->getJson($url)
                ->assertForbidden()
                ->assertJsonPath('code', 'TEST_CATALOG_FORBIDDEN');
        }

        $assignmentId = $this->assign($employer, $application, $test);
        $this->withToken($this->token($candidate))->postJson("/api/v1/tests/{$assignmentId}/start")->assertCreated();
        $this->withToken($this->token($candidate))->getJson("/api/v1/tests/{$test->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'TEST_CATALOG_FORBIDDEN');
    }

    public function test_employer_catalog_is_company_scoped_and_admin_keeps_full_access(): void
    {
        [$company, $employer] = $this->context();
        $own = $this->testWithQuestion($company);
        $otherCompany = Company::create(['name' => 'Other Co.', 'approval_status' => 'approved']);
        $other = $this->testWithQuestion($otherCompany, 'Other Assessment');

        $this->withToken($this->token($employer))->getJson('/api/v1/tests')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $own->id)
            ->assertJsonPath('data.data.0.questions.0.options.0.is_correct', true);
        $this->withToken($this->token($employer))->getJson("/api/v1/tests/{$other->id}")->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->token($admin))->getJson('/api/v1/tests')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_candidate_invitation_is_summary_only_and_does_not_query_options_or_grading(): void
    {
        [$company, $employer, $candidate, $application] = $this->context();
        $test = $this->testWithQuestion($company);
        $assignmentId = $this->assign($employer, $application, $test);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->withToken($this->token($candidate))->getJson('/api/v1/my/tests')
            ->assertOk()
            ->assertJsonPath('data.data.0.assignment_id', $assignmentId)
            ->assertJsonPath('data.data.0.test.question_count', 1)
            ->assertJsonMissingPath('data.data.0.test.questions')
            ->assertJsonMissingPath('data.data.0.test.options')
            ->assertJsonMissingPath('data.data.0.test.passing_score')
            ->assertJsonMissingPath('data.data.0.test.max_score')
            ->assertJsonMissingPath('data.data.0.test.company_id')
            ->assertJsonMissingPath('data.data.0.job_application');

        $sql = implode("\n", $queries);
        $this->assertStringNotContainsString('test_options', $sql);
        $this->assertStringNotContainsString('test_answer_gradings', $sql);
    }

    private function context(): array
    {
        $company = Company::create(['name' => 'Catalog Co. '.Str::random(5), 'approval_status' => 'approved']);
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create(['company_id' => $company->id, 'title' => 'Engineer', 'description' => 'APIs', 'employment_type' => 'full-time', 'experience_level' => 'mid-level', 'location' => 'Remote', 'status' => 'open']);
        $application = JobApplication::create(['job_posting_id' => $job->id, 'job_seeker_profile_id' => $profile->id, 'application_status_id' => ApplicationStatus::where('slug', 'under_review')->value('id')]);

        return [$company, $employer->load('employerProfile'), $candidate->load('jobSeekerProfile'), $application->load('jobPosting', 'applicationStatus')];
    }

    private function testWithQuestion(Company $company, string $title = 'Secure Assessment'): RecruitmentTest
    {
        $test = RecruitmentTest::forceCreate(['company_id' => $company->id, 'title' => $title, 'description' => 'Candidate-safe description.', 'instructions' => 'Complete all required questions.', 'duration_minutes' => 60, 'max_score' => 10, 'passing_score' => 7, 'is_active' => true]);
        $question = TestQuestion::create(['test_id' => $test->id, 'question_text' => 'Which verb updates a resource?', 'question_type' => 'single_choice', 'order_index' => 1, 'points' => 10, 'is_required' => true]);
        TestOption::create(['test_question_id' => $question->id, 'option_text' => 'PUT', 'order_index' => 1, 'is_correct' => true]);
        TestOption::create(['test_question_id' => $question->id, 'option_text' => 'GET', 'order_index' => 2, 'is_correct' => false]);

        return $test;
    }

    private function assign(User $employer, JobApplication $application, RecruitmentTest $test): int
    {
        return (int) $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/assign-test", ['test_id' => $test->id])->assertCreated()->json('data.id');
    }

    private function token(User $user): string
    {
        return $user->createToken(Str::random(8))->plainTextToken;
    }
}
