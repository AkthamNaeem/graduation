<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TestAttemptGradingStatus;
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
use App\Models\TestQuestion;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestScoreInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_max_score_is_system_managed_and_question_mutations_synchronize_fractional_totals(): void
    {
        [$employer] = $this->scenario('sync');
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson('/api/v1/tests', [
            'title' => 'Client Controlled',
            'duration_minutes' => 30,
            'max_score' => 100,
        ])->assertUnprocessable()->assertJsonPath('code', 'TEST_MAX_SCORE_IS_SYSTEM_MANAGED');

        $testId = $this->withToken($token)->postJson('/api/v1/tests', [
            'title' => 'Canonical Scores',
            'duration_minutes' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.max_score', '0.00')
            ->assertJsonPath('data.score_configuration_valid', false)
            ->json('data.id');

        $firstId = $this->createQuestion($token, $testId, 1, '2.50');
        $secondId = $this->createQuestion($token, $testId, 2, '1.25');
        $this->assertSame('3.75', RecruitmentTest::findOrFail($testId)->max_score);

        $this->withToken($token)->patchJson("/api/v1/tests/{$testId}/questions/{$secondId}", ['points' => '2.00'])
            ->assertOk();
        $this->withToken($token)->patchJson("/api/v1/tests/{$testId}", ['passing_score' => '4.00'])
            ->assertOk()
            ->assertJsonPath('data.max_score', '4.50')
            ->assertJsonPath('data.passing_score_percentage', 88.89)
            ->assertJsonPath('data.question_count', 2)
            ->assertJsonPath('data.score_configuration_valid', true);

        $this->withToken($token)->patchJson("/api/v1/tests/{$testId}/questions/{$secondId}", ['points' => '0.50'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'TEST_PASSING_SCORE_EXCEEDS_MAX_SCORE');
        $this->withToken($token)->deleteJson("/api/v1/tests/{$testId}/questions/{$firstId}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'TEST_PASSING_SCORE_EXCEEDS_MAX_SCORE');

        $test = RecruitmentTest::findOrFail($testId);
        $this->assertSame('4.50', $test->max_score);
        $this->assertSame('2.00', TestQuestion::findOrFail($secondId)->points);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test.question_created', 'entity_id' => $firstId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test.passing_score_updated', 'entity_id' => $testId]);
    }

    public function test_assignment_gate_rejects_zero_and_invalid_configuration_without_side_effects_and_repairs_drift(): void
    {
        [$employer, , $application] = $this->scenario('gate-zero');
        $test = $this->draftTest($employer, 'Zero Draft');
        $history = $application->statusHistory()->count();
        $notifications = $application->jobSeekerProfile->user->notifications()->count();

        $this->assign($employer, $application, $test)
            ->assertConflict()->assertJsonPath('code', 'TEST_HAS_NO_SCOREABLE_QUESTIONS');
        $this->assertDatabaseMissing('application_test_assignments', ['test_id' => $test->id]);
        $this->assertSame($history, $application->statusHistory()->count());
        $this->assertSame($notifications, $application->jobSeekerProfile->user->notifications()->count());

        [$employer2, , $application2] = $this->scenario('gate-valid');
        $valid = $this->draftTest($employer2, 'Repair Drift');
        $valid->questions()->create($this->questionAttributes(1, '10.00'));
        $valid->forceFill(['max_score' => '999.00', 'passing_score' => '5.00'])->save();
        $this->assign($employer2, $application2, $valid)->assertCreated();
        $this->assertSame('10.00', $valid->refresh()->max_score);

        [$employer3, , $application3] = $this->scenario('gate-invalid');
        $invalid = $this->draftTest($employer3, 'Invalid Passing');
        $invalid->questions()->create($this->questionAttributes(1, '10.00'));
        $invalid->forceFill(['max_score' => '10.00', 'passing_score' => '20.00'])->save();
        $this->assign($employer3, $application3, $invalid)
            ->assertConflict()->assertJsonPath('code', 'TEST_SCORE_CONFIGURATION_INVALID');
        $this->assertDatabaseMissing('application_test_assignments', ['test_id' => $invalid->id]);
    }

    public function test_submit_defense_rolls_back_corrupt_configuration(): void
    {
        [$employer, $candidate, $application] = $this->scenario('submit-invalid');
        $test = $this->draftTest($employer, 'Submit Defense');
        $test->questions()->create($this->questionAttributes(1, '10.00'));
        $test->forceFill(['max_score' => '10.00', 'passing_score' => '20.00'])->save();
        $assignment = ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
            'assigned_at' => now(),
        ]);
        $attempt = TestAttempt::create(['application_test_assignment_id' => $assignment->id, 'started_at' => now()]);
        $history = $application->statusHistory()->count();
        $notifications = $candidate->notifications()->count();

        $this->withToken($this->tokenFor($candidate))->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'TEST_SCORE_CONFIGURATION_INVALID');

        $this->assertNull($attempt->refresh()->submitted_at);
        $this->assertSame(0, $attempt->testAnswers()->whereHas('grading')->count());
        $this->assertSame($history, $application->statusHistory()->count());
        $this->assertSame($notifications, $candidate->notifications()->count());
    }

    public function test_passing_score_is_an_absolute_threshold_not_a_percentage(): void
    {
        [, , $application] = $this->scenario('absolute');

        foreach ([['100.00', true], ['150.00', false]] as [$passing, $expected]) {
            $test = RecruitmentTest::forceCreate([
                'company_id' => $application->jobPosting->company_id,
                'title' => "Threshold {$passing}",
                'duration_minutes' => 60,
                'max_score' => '200.00',
                'passing_score' => $passing,
                'is_active' => true,
            ]);
            $assignment = ApplicationTestAssignment::create([
                'job_application_id' => $application->id,
                'test_id' => $test->id,
                'assigned_by_user_id' => User::where('role', UserRole::EMPLOYER)->value('id'),
                'assigned_at' => now(),
            ]);
            $attempt = TestAttempt::create([
                'application_test_assignment_id' => $assignment->id,
                'started_at' => now(),
                'submitted_at' => now(),
                'total_score' => '120.00',
                'max_score' => '200.00',
                'percentage' => '60.00',
                'grading_status' => TestAttemptGradingStatus::FULLY_GRADED,
            ]);
            $attempt->load('applicationTestAssignment.test');
            $this->assertSame($expected, $attempt->passingScoreMet());
        }
    }

    public function test_legacy_normalization_updates_test_configuration_without_touching_historical_attempts(): void
    {
        [$employer, , $application] = $this->scenario('legacy-score');
        $test = $this->draftTest($employer, 'Legacy Drift');
        $test->questions()->create($this->questionAttributes(1, '2.50'));
        $test->questions()->create($this->questionAttributes(2, '1.25'));
        $test->forceFill(['max_score' => '99.00', 'passing_score' => '5.00'])->save();
        $assignment = ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
            'assigned_at' => now(),
        ]);
        $attempt = TestAttempt::create([
            'application_test_assignment_id' => $assignment->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'total_score' => '70.00',
            'max_score' => '99.00',
            'percentage' => '70.71',
        ]);

        $migration = require database_path('migrations/2026_07_18_000001_normalize_test_score_configuration.php');
        $migration->up();

        $this->assertSame('3.75', $test->refresh()->max_score);
        $this->assertNull($test->passing_score);
        $this->assertSame('70.00', $attempt->refresh()->total_score);
        $this->assertSame('99.00', $attempt->max_score);
        $this->assertSame('70.71', $attempt->percentage);
    }

    private function createQuestion(string $token, int $testId, int $order, string $points): int
    {
        return $this->withToken($token)->postJson("/api/v1/tests/{$testId}/questions", $this->questionAttributes($order, $points))
            ->assertCreated()->json('data.id');
    }

    private function questionAttributes(int $order, string $points): array
    {
        return ['question_text' => "Question {$order}", 'question_type' => 'short_text', 'order_index' => $order, 'points' => $points, 'is_required' => false];
    }

    private function draftTest(User $employer, string $title): RecruitmentTest
    {
        return RecruitmentTest::forceCreate([
            'company_id' => $employer->employerProfile->company_id,
            'title' => $title,
            'duration_minutes' => 60,
            'max_score' => '0.00',
            'passing_score' => null,
            'is_active' => true,
        ]);
    }

    private function assign(User $employer, JobApplication $application, RecruitmentTest $test)
    {
        return $this->withToken($this->tokenFor($employer))->postJson("/api/v1/applications/{$application->id}/assign-test", ['test_id' => $test->id]);
    }

    /** @return array{User,User,JobApplication} */
    private function scenario(string $suffix): array
    {
        $company = Company::create(['name' => "Score Co {$suffix}", 'approval_status' => 'approved']);
        $employer = User::factory()->create(['email' => "employer-{$suffix}@example.com", 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create(['email' => "candidate-{$suffix}@example.com", 'role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => "Job {$suffix}",
            'description' => 'Description',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => ApplicationStatus::where('slug', 'under_review')->value('id'),
        ])->load(['jobPosting', 'jobSeekerProfile.user', 'applicationStatus']);

        return [$employer->load('employerProfile'), $candidate, $application];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
