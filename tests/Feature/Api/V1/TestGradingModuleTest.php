<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\TestSubmitted;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Test as RecruitmentTest;
use App\Models\TestAnswer;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\TestGradingService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class TestGradingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_objective_submit_auto_grades_and_exposes_role_safe_results(): void
    {
        $scenario = $this->scenario('objective', 10, 3);
        $single = $this->question($scenario['test'], 'single_choice', 1, 4, true, [0]);
        $trueFalse = $this->question($scenario['test'], 'true_false', 2, 2, true, [0]);
        $optional = $this->question($scenario['test'], 'single_choice', 3, 3, false, [0]);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $single, [$single->options[0]->id]);
        $this->choiceAnswer($data['attempt'], $trueFalse, [$trueFalse->options[1]->id]);

        $historyBefore = $scenario['application']->statusHistory()->count();
        $this->submit($scenario['candidate'], $data['assignment'])->assertOk()
            ->assertJsonPath('data.grading_status', 'auto_graded')
            ->assertJsonPath('data.objective_score', '4.00')
            ->assertJsonPath('data.objective_max_score', '9.00')
            ->assertJsonPath('data.total_score', '4.00')
            ->assertJsonPath('data.percentage', '44.44')
            ->assertJsonPath('data.is_passing_score_met', true);

        $attempt = $data['attempt']->fresh();
        $this->assertSame('auto_graded', $attempt->grading_status->value);
        $this->assertDatabaseCount('test_answer_gradings', 2);
        $this->assertDatabaseHas('test_answer_gradings', [
            'test_answer_id' => $single->testAnswers()->firstOrFail()->id,
            'is_correct' => true,
            'awarded_points' => 4,
        ]);
        $this->assertDatabaseHas('test_answer_gradings', [
            'test_answer_id' => $trueFalse->testAnswers()->firstOrFail()->id,
            'is_correct' => false,
            'awarded_points' => 0,
        ]);
        $this->assertSame('test_completed', $scenario['application']->fresh()->applicationStatus->slug);
        $this->assertSame($historyBefore + 1, $scenario['application']->statusHistory()->count());
        $this->assertFalse(\Schema::hasColumn('job_applications', 'score'));
        $audit = AuditLog::query()->where('action', 'test_attempt.auto_graded')->firstOrFail();
        $this->assertSame($attempt->id, $audit->entity_id);
        $this->assertArrayNotHasKey('answers', $audit->after_values);
        $this->assertArrayNotHasKey('correct_options', $audit->after_values);

        $url = "/api/v1/test-attempts/{$attempt->id}/result";
        $this->withToken($this->tokenFor($scenario['candidate']))->getJson($url)
            ->assertOk()
            ->assertJsonMissingPath('data.breakdown')
            ->assertJsonMissing(['is_correct', 'correct_options', 'explanation', 'evaluated_by_user_id']);

        $this->withToken($this->tokenFor($scenario['employer']))->getJson($url)
            ->assertOk()
            ->assertJsonCount(3, 'data.breakdown')
            ->assertJsonPath('data.breakdown.0.is_correct', true)
            ->assertJsonPath('data.breakdown.1.is_correct', false)
            ->assertJsonPath('data.breakdown.2.question_id', $optional->id)
            ->assertJsonPath('data.breakdown.2.answered', false)
            ->assertJsonPath('data.breakdown.2.awarded_points', '0.00');

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->tokenFor($admin))->getJson($url)->assertOk()->assertJsonCount(3, 'data.breakdown');
    }

    public function test_multiple_choice_uses_exact_set_match_without_partial_credit(): void
    {
        $cases = [
            'reversed_exact_set' => ['selected' => [1, 0], 'correct' => true],
            'missing_correct_option' => ['selected' => [0], 'correct' => false],
            'extra_incorrect_option' => ['selected' => [0, 1, 2], 'correct' => false],
            'incorrect_subset' => ['selected' => [2], 'correct' => false],
        ];

        foreach ($cases as $suffix => $case) {
            $scenario = $this->scenario($suffix, 3);
            $question = $this->question($scenario['test'], 'multiple_choice', 1, 3, true, [0, 1]);
            $data = $this->assignAndStart($scenario);
            $this->choiceAnswer(
                $data['attempt'],
                $question,
                array_map(fn (int $index): int => $question->options[$index]->id, $case['selected']),
            );

            $this->submit($scenario['candidate'], $data['assignment'])->assertOk();

            $grading = $question->testAnswers()->firstOrFail()->grading()->firstOrFail();
            $this->assertSame($case['correct'], $grading->is_correct, $suffix);
            $this->assertSame($case['correct'] ? '3.00' : '0.00', $grading->awarded_points, $suffix);
        }
    }

    public function test_mixed_test_keeps_final_totals_pending_for_manual_grading(): void
    {
        Storage::fake('local');
        $scenario = $this->scenario('mixed', 15, 8);
        $single = $this->question($scenario['test'], 'single_choice', 1, 2, true, [0]);
        $multiple = $this->question($scenario['test'], 'multiple_choice', 2, 3, true, [0, 1]);
        $trueFalse = $this->question($scenario['test'], 'true_false', 3, 1, true, [0]);
        $short = $this->question($scenario['test'], 'short_text', 4, 4);
        $file = $this->question($scenario['test'], 'file_upload', 5, 5);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $single, [$single->options[0]->id]);
        $this->choiceAnswer($data['attempt'], $multiple, [$multiple->options[0]->id]);
        $this->choiceAnswer($data['attempt'], $trueFalse, [$trueFalse->options[0]->id]);
        TestAnswer::create([
            'test_attempt_id' => $data['attempt']->id,
            'test_question_id' => $short->id,
            'answer_text' => 'Manual response',
        ]);
        Storage::disk('local')->put('test-answers/manual/solution.pdf', 'pdf');
        TestAnswer::create([
            'test_attempt_id' => $data['attempt']->id,
            'test_question_id' => $file->id,
            'file_path' => 'test-answers/manual/solution.pdf',
            'file_disk' => 'local',
            'file_original_name' => 'solution.pdf',
            'file_mime_type' => 'application/pdf',
            'file_size' => 3,
        ]);

        $this->submit($scenario['candidate'], $data['assignment'])->assertOk()
            ->assertJsonPath('data.grading_status', 'manual_grading_required')
            ->assertJsonPath('data.objective_score', '3.00')
            ->assertJsonPath('data.objective_max_score', '6.00')
            ->assertJsonPath('data.manual_score', null)
            ->assertJsonPath('data.manual_max_score', '9.00')
            ->assertJsonPath('data.total_score', null)
            ->assertJsonPath('data.max_score', '15.00')
            ->assertJsonPath('data.percentage', null)
            ->assertJsonPath('data.is_passing_score_met', null);

        $url = "/api/v1/test-attempts/{$data['attempt']->id}/result";
        $this->withToken($this->tokenFor($scenario['employer']))->getJson($url)
            ->assertOk()
            ->assertJsonCount(5, 'data.breakdown')
            ->assertJsonPath('data.breakdown.3.requires_manual_grading', true)
            ->assertJsonPath('data.breakdown.3.awarded_points', null)
            ->assertJsonPath('data.breakdown.4.file.original_name', 'solution.pdf')
            ->assertJsonMissing(['file_path', 'file_disk']);

        $this->withToken($this->tokenFor($scenario['employer']))
            ->postJson("/api/v1/tests/{$data['attempt']->id}/evaluate", [
                'score' => 12,
                'feedback' => 'Legacy overall evaluation.',
            ])->assertOk()
            ->assertJsonPath('data.score', '12.00')
            ->assertJsonPath('data.grading_status', 'manual_grading_required')
            ->assertJsonPath('data.manual_score', null)
            ->assertJsonPath('data.total_score', null)
            ->assertJsonPath('data.percentage', null);
    }

    public function test_zero_point_objective_test_is_rejected_by_submit_defense(): void
    {
        $scenario = $this->scenario('zero', 1, 0);
        $question = $this->question($scenario['test'], 'single_choice', 1, 0, true, [0]);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $question, [$question->options[0]->id]);

        $this->submit($scenario['candidate'], $data['assignment'])
            ->assertConflict()
            ->assertJsonPath('code', 'TEST_HAS_NO_SCOREABLE_QUESTIONS');
        $this->assertNull($data['attempt']->refresh()->submitted_at);
    }

    public function test_result_authorization_and_pre_submit_conflict_are_enforced(): void
    {
        $scenario = $this->scenario('owner', 2);
        $this->question($scenario['test'], 'single_choice', 1, 2, false, [0]);
        $data = $this->assignAndStart($scenario);
        $url = "/api/v1/test-attempts/{$data['attempt']->id}/result";

        $this->withToken($this->tokenFor($scenario['candidate']))->getJson($url)->assertStatus(409);

        $foreign = $this->scenario('foreign', 2);
        $this->withToken($this->tokenFor($foreign['candidate']))->getJson($url)->assertForbidden();
        $this->withToken($this->tokenFor($foreign['employer']))->getJson($url)->assertForbidden();
    }

    public function test_second_submit_does_not_duplicate_grading_history_or_notification(): void
    {
        $scenario = $this->scenario('idempotent', 2);
        $question = $this->question($scenario['test'], 'single_choice', 1, 2, true, [0]);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $question, [$question->options[0]->id]);

        $historyBefore = $scenario['application']->statusHistory()->count();
        $this->submit($scenario['candidate'], $data['assignment'])->assertOk();
        $gradingCount = \DB::table('test_answer_gradings')->count();
        $notificationCount = \DB::table('notifications')->where('type', 'test.submitted')->count();

        $this->submit($scenario['candidate'], $data['assignment'])->assertStatus(409);

        $this->assertSame($gradingCount, \DB::table('test_answer_gradings')->count());
        $this->assertSame($notificationCount, \DB::table('notifications')->where('type', 'test.submitted')->count());
        $this->assertSame($historyBefore + 1, $scenario['application']->statusHistory()->count());
    }

    public function test_grading_failure_rolls_back_submit_workflow_and_events(): void
    {
        Event::fake([TestSubmitted::class]);
        $scenario = $this->scenario('grading-failure', 2);
        $question = $this->question($scenario['test'], 'single_choice', 1, 2, true, [0]);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $question, [$question->options[0]->id]);
        $historyBefore = $scenario['application']->statusHistory()->count();

        $this->mock(TestGradingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('gradeSubmittedAttempt')->once()->andThrow(new RuntimeException('grading failed'));
        });

        $this->submit($scenario['candidate'], $data['assignment'])->assertStatus(500);

        $this->assertNull($data['attempt']->fresh()->submitted_at);
        $this->assertSame('pending', $data['attempt']->fresh()->grading_status->value);
        $this->assertDatabaseCount('test_answer_gradings', 0);
        $this->assertSame('test_pending', $scenario['application']->fresh()->applicationStatus->slug);
        $this->assertSame($historyBefore, $scenario['application']->statusHistory()->count());
        Event::assertNotDispatched(TestSubmitted::class);
    }

    public function test_workflow_failure_rolls_back_grading_and_submit(): void
    {
        Event::fake([TestSubmitted::class]);
        $scenario = $this->scenario('workflow-failure', 2);
        $question = $this->question($scenario['test'], 'single_choice', 1, 2, true, [0]);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $question, [$question->options[0]->id]);
        $historyBefore = $scenario['application']->statusHistory()->count();

        $this->mock(ApplicationWorkflowService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changeStatus')->once()->andThrow(new RuntimeException('workflow failed'));
        });

        $this->submit($scenario['candidate'], $data['assignment'])->assertStatus(500);

        $attempt = $data['attempt']->fresh();
        $this->assertNull($attempt->submitted_at);
        $this->assertNull($attempt->objective_score);
        $this->assertSame('pending', $attempt->grading_status->value);
        $this->assertDatabaseCount('test_answer_gradings', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'test_attempt.auto_graded', 'entity_id' => $attempt->id]);
        $this->assertSame('test_pending', $scenario['application']->fresh()->applicationStatus->slug);
        $this->assertSame($historyBefore, $scenario['application']->statusHistory()->count());
        Event::assertNotDispatched(TestSubmitted::class);
    }

    private function scenario(string $suffix, float $maxScore, ?float $passingScore = null): array
    {
        $company = Company::create(['name' => "Company {$suffix}", 'approval_status' => 'approved']);
        $employer = User::factory()->create(['email' => "employer-{$suffix}@example.com", 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create(['email' => "candidate-{$suffix}@example.com", 'role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Role',
            'description' => 'Build APIs',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'status' => 'open',
        ]);
        $status = ApplicationStatus::query()->where('slug', 'test_pending')->firstOrFail();
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => $status->id,
        ]);
        $application->statusHistory()->create([
            'to_application_status_id' => $status->id,
            'changed_by_user_id' => $employer->id,
            'note' => 'Test assigned.',
        ]);
        $test = RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => "Assessment {$suffix}",
            'duration_minutes' => 60,
            'max_score' => $maxScore,
            'passing_score' => $passingScore,
            'is_active' => true,
        ]);

        return compact('company', 'employer', 'candidate', 'application', 'test');
    }

    private function assignAndStart(array $scenario): array
    {
        $assignment = ApplicationTestAssignment::create([
            'job_application_id' => $scenario['application']->id,
            'test_id' => $scenario['test']->id,
            'assigned_by_user_id' => $scenario['employer']->id,
            'assigned_at' => now(),
        ]);
        $attempt = TestAttempt::create(['application_test_assignment_id' => $assignment->id, 'started_at' => now()]);

        return compact('assignment', 'attempt');
    }

    private function question(
        RecruitmentTest $test,
        string $type,
        int $order,
        float $points,
        bool $required = true,
        array $correctIndexes = [],
    ): TestQuestion {
        $question = $test->questions()->create([
            'question_text' => "Question {$order}",
            'question_type' => $type,
            'order_index' => $order,
            'points' => $points,
            'is_required' => $required,
        ]);

        if (in_array($type, ['single_choice', 'multiple_choice', 'true_false'], true)) {
            $labels = $type === 'true_false' ? ['True', 'False'] : ['First', 'Second', 'Third'];
            foreach ($labels as $index => $label) {
                $question->options()->create([
                    'option_text' => "{$label} {$order}",
                    'order_index' => $index + 1,
                    'is_correct' => in_array($index, $correctIndexes, true),
                ]);
            }
        }

        return $question->load('options');
    }

    private function choiceAnswer(TestAttempt $attempt, TestQuestion $question, array $optionIds): TestAnswer
    {
        $answer = TestAnswer::create([
            'test_attempt_id' => $attempt->id,
            'test_question_id' => $question->id,
        ]);
        $answer->selectedOptions()->attach($optionIds);

        return $answer;
    }

    private function submit(User $candidate, ApplicationTestAssignment $assignment)
    {
        return $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
