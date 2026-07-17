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
use App\Models\TestOption;
use App\Models\TestQuestion;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestAttemptQuestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_owner_reads_ordered_safe_questions_after_start_without_loading_answer_keys(): void
    {
        [$employer, $candidate, $assignment] = $this->scenario();
        $attemptId = (int) $this->withToken($this->token($candidate))->postJson("/api/v1/tests/{$assignment->id}/start")->assertCreated()->json('data.id');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->withToken($this->token($candidate))->getJson("/api/v1/test-attempts/{$attemptId}/questions")
            ->assertOk()
            ->assertJsonPath('data.0.order_index', 1)
            ->assertJsonPath('data.0.options.0.option_text', 'PUT')
            ->assertJsonPath('data.0.options.1.option_text', 'GET')
            ->assertJsonMissingPath('data.0.points')
            ->assertJsonMissingPath('data.0.test_id')
            ->assertJsonMissingPath('data.0.options.0.is_correct')
            ->assertJsonMissingPath('data.0.options.0.test_question_id')
            ->assertJsonMissingPath('data.0.passing_score')
            ->assertJsonMissingPath('data.0.grading');

        $optionQuery = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'test_options'));
        $this->assertIsString($optionQuery);
        $this->assertStringNotContainsString('is_correct', $optionQuery);
        $this->assertStringNotContainsString('test_answer_gradings', implode("\n", $queries));

        $this->withToken($this->token($employer))->getJson("/api/v1/test-attempts/{$attemptId}/questions")->assertForbidden();
    }

    public function test_cross_candidate_and_missing_attempt_are_blocked(): void
    {
        [, $candidate, $assignment] = $this->scenario();
        $this->withToken($this->token($candidate))->getJson('/api/v1/test-attempts/999999/questions')->assertNotFound();

        $other = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $other->id]);
        $attempt = TestAttempt::create(['application_test_assignment_id' => $assignment->id, 'started_at' => now()]);
        $this->withToken($this->token($other))->getJson("/api/v1/test-attempts/{$attempt->id}/questions")->assertForbidden();
    }

    public function test_candidate_uses_returned_ids_to_answer_submit_and_keep_historical_safe_read(): void
    {
        [, $candidate, $assignment] = $this->scenario();
        $attemptId = (int) $this->withToken($this->token($candidate))->postJson("/api/v1/tests/{$assignment->id}/start")->assertCreated()->json('data.id');
        $questions = $this->withToken($this->token($candidate))->getJson("/api/v1/test-attempts/{$attemptId}/questions")->assertOk()->json('data');
        $questionId = $questions[0]['id'];
        $optionId = $questions[0]['options'][0]['id'];

        $this->withToken($this->token($candidate))->putJson("/api/v1/test-attempts/{$attemptId}/answers/{$questionId}", ['selected_option_ids' => [$optionId]])->assertOk();
        $this->withToken($this->token($candidate))->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true])->assertOk();
        $this->withToken($this->token($candidate))->getJson("/api/v1/test-attempts/{$attemptId}/questions")
            ->assertOk()
            ->assertJsonMissingPath('data.0.options.0.is_correct');
        $this->withToken($this->token($candidate))->patchJson("/api/v1/test-attempts/{$attemptId}/answers/{$questionId}", ['selected_option_ids' => [$optionId]])->assertConflict();
        $this->withToken($this->token($candidate))->getJson("/api/v1/test-attempts/{$attemptId}/result")
            ->assertOk()
            ->assertJsonMissingPath('data.breakdown')
            ->assertJsonMissingPath('data.correct_options');
    }

    private function scenario(): array
    {
        $company = Company::create(['name' => 'Attempt Co. '.Str::random(4), 'approval_status' => 'approved']);
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create(['company_id' => $company->id, 'title' => 'Engineer', 'description' => 'APIs', 'employment_type' => 'full-time', 'experience_level' => 'mid-level', 'location' => 'Remote', 'status' => 'open']);
        $application = JobApplication::create(['job_posting_id' => $job->id, 'job_seeker_profile_id' => $profile->id, 'application_status_id' => ApplicationStatus::where('slug', 'under_review')->value('id')]);
        $test = RecruitmentTest::forceCreate(['company_id' => $company->id, 'title' => 'Backend Assessment', 'description' => 'Safe summary.', 'instructions' => 'Answer the questions.', 'duration_minutes' => 60, 'max_score' => 10, 'passing_score' => 7, 'is_active' => true]);
        $question = TestQuestion::create(['test_id' => $test->id, 'question_text' => 'Which verb updates a resource?', 'question_type' => 'single_choice', 'order_index' => 1, 'points' => 10, 'is_required' => true]);
        TestOption::create(['test_question_id' => $question->id, 'option_text' => 'PUT', 'order_index' => 1, 'is_correct' => true]);
        TestOption::create(['test_question_id' => $question->id, 'option_text' => 'GET', 'order_index' => 2, 'is_correct' => false]);
        $assignmentId = (int) $this->withToken($this->token($employer))->postJson("/api/v1/applications/{$application->id}/assign-test", ['test_id' => $test->id])->assertCreated()->json('data.id');

        return [$employer->load('employerProfile'), $candidate->load('jobSeekerProfile'), ApplicationTestAssignment::findOrFail($assignmentId)];
    }

    private function token(User $user): string
    {
        return $user->createToken(Str::random(8))->plainTextToken;
    }
}
