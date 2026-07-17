<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
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
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualGradingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_employer_grades_all_subjective_answers_and_finalizes_mixed_result(): void
    {
        Storage::fake('local');
        $scenario = $this->scenario('finalize', 20, 12);
        $objective = $this->question($scenario['test'], 'single_choice', 1, 4, true, [0]);
        $short = $this->question($scenario['test'], 'short_text', 2, 3);
        $long = $this->question($scenario['test'], 'long_text', 3, 5);
        $file = $this->question($scenario['test'], 'file_upload', 4, 4);
        $optional = $this->question($scenario['test'], 'long_text', 5, 4, false);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $objective, [$objective->options[0]->id]);
        $this->textAnswer($data['attempt'], $short, 'Short response');
        $this->textAnswer($data['attempt'], $long, 'Long response');
        $this->fileAnswer($data['attempt'], $file);

        $this->submit($scenario['candidate'], $data['assignment'])
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'manual_grading_required')
            ->assertJsonPath('data.objective_score', '4.00')
            ->assertJsonPath('data.manual_max_score', '16.00');
        $notificationCountAfterSubmit = \DB::table('notifications')->count();

        $this->grade($scenario['employer'], $data['attempt'], $short, 2.5, '  Good short answer.  ')
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'manual_grading_required')
            ->assertJsonPath('data.manual_score', null)
            ->assertJsonPath('data.manual_grading_progress.total', 4)
            ->assertJsonPath('data.manual_grading_progress.graded', 1)
            ->assertJsonPath('data.manual_grading_progress.remaining', 2)
            ->assertJsonPath('data.manual_grading_progress.complete', false);

        $this->grade($scenario['employer'], $data['attempt'], $long, 4, 'Clear explanation.')->assertOk();
        $this->grade($scenario['employer'], $data['attempt'], $file, 3.5, 'Solution works.')
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'fully_graded')
            ->assertJsonPath('data.objective_score', '4.00')
            ->assertJsonPath('data.manual_score', '10.00')
            ->assertJsonPath('data.total_score', '14.00')
            ->assertJsonPath('data.max_score', '20.00')
            ->assertJsonPath('data.percentage', '70.00')
            ->assertJsonPath('data.is_passing_score_met', true)
            ->assertJsonPath('data.manual_grading_progress.total', 4)
            ->assertJsonPath('data.manual_grading_progress.graded', 3)
            ->assertJsonPath('data.manual_grading_progress.remaining', 0)
            ->assertJsonPath('data.manual_grading_progress.complete', true);

        $attempt = $data['attempt']->fresh();
        $this->assertNotNull($attempt->manually_graded_at);
        $this->assertSame('test_completed', $scenario['application']->fresh()->applicationStatus->slug);
        $this->assertFalse(\Schema::hasColumn('job_applications', 'score'));
        $this->assertDatabaseHas('test_answer_gradings', [
            'test_answer_id' => $short->testAnswers()->firstOrFail()->id,
            'grading_type' => 'manual',
            'is_correct' => null,
            'awarded_points' => 2.5,
            'graded_by' => $scenario['employer']->id,
            'explanation' => 'Good short answer.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test_attempt.fully_graded', 'actor_user_id' => $scenario['employer']->id]);
        $this->assertSame($notificationCountAfterSubmit, \DB::table('notifications')->count());

        $url = "/api/v1/test-attempts/{$attempt->id}/result";
        $this->withToken($this->tokenFor($scenario['employer']))->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.breakdown.1.reviewer_note', 'Good short answer.')
            ->assertJsonPath('data.breakdown.1.graded_by.id', $scenario['employer']->id)
            ->assertJsonPath('data.breakdown.4.question_id', $optional->id)
            ->assertJsonPath('data.breakdown.4.answered', false)
            ->assertJsonPath('data.breakdown.4.awarded_points', '0.00')
            ->assertJsonPath('data.breakdown.4.requires_manual_grading', false);

        $this->withToken($this->tokenFor($scenario['candidate']))->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'fully_graded')
            ->assertJsonPath('data.manual_score', '10.00')
            ->assertJsonMissingPath('data.breakdown')
            ->assertJsonMissing(['reviewer_note', 'graded_by', 'correct_options', 'is_correct', 'explanation']);
    }

    public function test_manual_grading_validation_and_authorization_are_enforced(): void
    {
        $scenario = $this->scenario('validation', 10);
        $objective = $this->question($scenario['test'], 'single_choice', 1, 2, true, [0]);
        $short = $this->question($scenario['test'], 'short_text', 2, 4);
        $unansweredOptional = $this->question($scenario['test'], 'long_text', 3, 4, false);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $objective, [$objective->options[0]->id]);
        $this->textAnswer($data['attempt'], $short, 'Answer');

        $this->grade($scenario['employer'], $data['attempt'], $short, 2)->assertStatus(409);
        $this->submit($scenario['candidate'], $data['assignment'])->assertOk();

        $this->grade($scenario['employer'], $data['attempt'], $short, -1)
            ->assertUnprocessable()->assertJsonValidationErrors(['awarded_points']);
        $this->grade($scenario['employer'], $data['attempt'], $short, 4.5)
            ->assertUnprocessable()->assertJsonValidationErrors(['awarded_points']);
        $this->grade($scenario['employer'], $data['attempt'], $objective, 1)
            ->assertUnprocessable()->assertJsonValidationErrors(['question_id']);
        $this->grade($scenario['employer'], $data['attempt'], $unansweredOptional, 1)->assertNotFound();

        $foreign = $this->scenario('foreign', 5);
        $foreignQuestion = $this->question($foreign['test'], 'short_text', 1, 5);
        $this->grade($scenario['employer'], $data['attempt'], $foreignQuestion, 1)
            ->assertUnprocessable()->assertJsonValidationErrors(['question_id']);
        $this->grade($foreign['employer'], $data['attempt'], $short, 1)->assertForbidden();
        $this->grade($scenario['candidate'], $data['attempt'], $short, 1)->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->grade($admin, $data['attempt'], $short, 3, 'Admin review')
            ->assertOk()->assertJsonPath('data.grading_status', 'fully_graded');
        $this->assertDatabaseHas('test_answer_gradings', [
            'test_answer_id' => $short->testAnswers()->firstOrFail()->id,
            'graded_by' => $admin->id,
        ]);
    }

    public function test_manual_grade_can_be_updated_and_deleted_with_recalculation_and_audit(): void
    {
        $scenario = $this->scenario('edit', 5, 3);
        $short = $this->question($scenario['test'], 'short_text', 1, 5);
        $data = $this->assignAndStart($scenario);
        $this->textAnswer($data['attempt'], $short, 'Answer');
        $this->submit($scenario['candidate'], $data['assignment'])->assertOk();

        $this->grade($scenario['employer'], $data['attempt'], $short, 4, 'First')
            ->assertOk()->assertJsonPath('data.grading_status', 'fully_graded');
        $this->grade($scenario['employer'], $data['attempt'], $short, 3, '  Revised  ', 'patch')
            ->assertOk()
            ->assertJsonPath('data.manual_score', '3.00')
            ->assertJsonPath('data.total_score', '3.00')
            ->assertJsonPath('data.percentage', '60.00')
            ->assertJsonPath('data.is_passing_score_met', true)
            ->assertJsonPath('data.breakdown.0.reviewer_note', 'Revised');

        $this->assertDatabaseHas('audit_logs', ['action' => 'test_answer.manual_grading_updated']);
        $updatedAudit = AuditLog::query()->where('action', 'test_answer.manual_grading_updated')->firstOrFail();
        $this->assertSame('4.00', $updatedAudit->before_values['awarded_points']);
        $this->assertSame('3.00', $updatedAudit->after_values['awarded_points']);
        $this->assertArrayNotHasKey('reviewer_note', $updatedAudit->metadata);
        $this->assertSame(1, AuditLog::query()->where('action', 'test_attempt.fully_graded')->count());

        $this->withToken($this->tokenFor($scenario['employer']))
            ->deleteJson($this->gradeUrl($data['attempt'], $short))
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'manual_grading_required')
            ->assertJsonPath('data.manual_score', null)
            ->assertJsonPath('data.total_score', null)
            ->assertJsonPath('data.percentage', null)
            ->assertJsonPath('data.is_passing_score_met', null)
            ->assertJsonPath('data.manually_graded_at', null);

        $this->assertDatabaseHas('audit_logs', ['action' => 'test_answer.manual_grading_removed']);
    }

    public function test_automatic_grading_cannot_be_deleted_or_overridden(): void
    {
        $scenario = $this->scenario('automatic', 2);
        $objective = $this->question($scenario['test'], 'single_choice', 1, 2, true, [0]);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $objective, [$objective->options[0]->id]);
        $this->submit($scenario['candidate'], $data['assignment'])->assertOk();

        $this->grade($scenario['employer'], $data['attempt'], $objective, 0)
            ->assertUnprocessable()->assertJsonValidationErrors(['question_id']);
        $this->withToken($this->tokenFor($scenario['employer']))
            ->deleteJson($this->gradeUrl($data['attempt'], $objective))
            ->assertUnprocessable()->assertJsonValidationErrors(['question_id']);

        $this->assertDatabaseHas('test_answer_gradings', [
            'test_answer_id' => $objective->testAnswers()->firstOrFail()->id,
            'grading_type' => 'automatic',
            'awarded_points' => 2,
        ]);
    }

    public function test_bulk_manual_grading_is_atomic_and_finalizes_only_when_valid(): void
    {
        $scenario = $this->scenario('bulk', 10);
        $objective = $this->question($scenario['test'], 'single_choice', 1, 0, true, [0]);
        $short = $this->question($scenario['test'], 'short_text', 2, 4);
        $long = $this->question($scenario['test'], 'long_text', 3, 6);
        $data = $this->assignAndStart($scenario);
        $this->choiceAnswer($data['attempt'], $objective, [$objective->options[0]->id]);
        $this->textAnswer($data['attempt'], $short, 'Short');
        $this->textAnswer($data['attempt'], $long, 'Long');
        $this->submit($scenario['candidate'], $data['assignment'])->assertOk();
        $url = "/api/v1/test-attempts/{$data['attempt']->id}/gradings/bulk";

        $this->withToken($this->tokenFor($scenario['employer']))->postJson($url, ['gradings' => [
            ['question_id' => $short->id, 'awarded_points' => 3],
            ['question_id' => $long->id, 'awarded_points' => 7],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('test_answer_gradings', 1);

        $this->withToken($this->tokenFor($scenario['employer']))->postJson($url, ['gradings' => [
            ['question_id' => $short->id, 'awarded_points' => 3],
            ['question_id' => $short->id, 'awarded_points' => 2],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['gradings.1.question_id']);
        $this->assertDatabaseCount('test_answer_gradings', 1);

        $this->withToken($this->tokenFor($scenario['employer']))->postJson($url, ['gradings' => [
            ['question_id' => $short->id, 'awarded_points' => 3],
            ['question_id' => $objective->id, 'awarded_points' => 0],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['gradings']);
        $this->assertDatabaseCount('test_answer_gradings', 1);

        $this->withToken($this->tokenFor($scenario['employer']))->postJson($url, ['gradings' => [
            ['question_id' => $short->id, 'awarded_points' => 3, 'reviewer_note' => 'Clear'],
            ['question_id' => $long->id, 'awarded_points' => 5.5, 'reviewer_note' => 'Good'],
        ]])->assertOk()
            ->assertJsonPath('data.grading_status', 'fully_graded')
            ->assertJsonPath('data.manual_score', '8.50')
            ->assertJsonPath('data.total_score', '8.50')
            ->assertJsonPath('data.percentage', '85.00');
        $this->assertDatabaseCount('test_answer_gradings', 3);
    }

    public function test_optional_unanswered_subjective_question_finalizes_with_zero_without_fake_answer(): void
    {
        $scenario = $this->scenario('optional', 5, 0);
        $optional = $this->question($scenario['test'], 'long_text', 1, 5, false);
        $data = $this->assignAndStart($scenario);

        $this->submit($scenario['candidate'], $data['assignment'])
            ->assertOk()
            ->assertJsonPath('data.grading_status', 'fully_graded')
            ->assertJsonPath('data.manual_score', '0.00')
            ->assertJsonPath('data.total_score', '0.00')
            ->assertJsonPath('data.max_score', '5.00')
            ->assertJsonPath('data.percentage', '0.00')
            ->assertJsonPath('data.is_passing_score_met', true);

        $this->assertDatabaseCount('test_answers', 0);
        $this->assertDatabaseCount('test_answer_gradings', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test_attempt.fully_graded',
            'actor_user_id' => $scenario['candidate']->id,
        ]);
        $url = "/api/v1/test-attempts/{$data['attempt']->id}/result";
        $this->withToken($this->tokenFor($scenario['employer']))->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.manual_grading_progress.total', 1)
            ->assertJsonPath('data.manual_grading_progress.graded', 0)
            ->assertJsonPath('data.manual_grading_progress.remaining', 0)
            ->assertJsonPath('data.manual_grading_progress.complete', true)
            ->assertJsonPath('data.breakdown.0.question_id', $optional->id)
            ->assertJsonPath('data.breakdown.0.answered', false)
            ->assertJsonPath('data.breakdown.0.awarded_points', '0.00');
    }

    public function test_zero_max_subjective_result_is_rejected_by_submit_defense(): void
    {
        $scenario = $this->scenario('zero-manual', 1, 0);
        $short = $this->question($scenario['test'], 'short_text', 1, 0);
        $data = $this->assignAndStart($scenario);
        $this->textAnswer($data['attempt'], $short, 'Answer');
        $this->submit($scenario['candidate'], $data['assignment'])
            ->assertConflict()
            ->assertJsonPath('code', 'TEST_HAS_NO_SCOREABLE_QUESTIONS');
        $this->assertNull($data['attempt']->refresh()->submitted_at);
    }

    public function test_legacy_evaluation_does_not_replace_finalized_per_answer_totals(): void
    {
        $scenario = $this->scenario('legacy', 5);
        $short = $this->question($scenario['test'], 'short_text', 1, 5);
        $data = $this->assignAndStart($scenario);
        $this->textAnswer($data['attempt'], $short, 'Answer');
        $this->submit($scenario['candidate'], $data['assignment'])->assertOk();
        $this->grade($scenario['employer'], $data['attempt'], $short, 4)->assertOk();

        $this->withToken($this->tokenFor($scenario['employer']))
            ->postJson("/api/v1/tests/{$data['attempt']->id}/evaluate", [
                'score' => 1,
                'feedback' => 'Legacy general feedback.',
            ])->assertOk()
            ->assertJsonPath('data.score', '1.00')
            ->assertJsonPath('data.grading_status', 'fully_graded')
            ->assertJsonPath('data.manual_score', '4.00')
            ->assertJsonPath('data.total_score', '4.00')
            ->assertJsonPath('data.percentage', '80.00');

        $this->assertDatabaseHas('test_answer_gradings', [
            'test_answer_id' => $short->testAnswers()->firstOrFail()->id,
            'awarded_points' => 4,
            'grading_type' => 'manual',
        ]);
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
            foreach (['First', 'Second'] as $index => $label) {
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
        $answer = TestAnswer::create(['test_attempt_id' => $attempt->id, 'test_question_id' => $question->id]);
        $answer->selectedOptions()->attach($optionIds);

        return $answer;
    }

    private function textAnswer(TestAttempt $attempt, TestQuestion $question, string $text): TestAnswer
    {
        return TestAnswer::create([
            'test_attempt_id' => $attempt->id,
            'test_question_id' => $question->id,
            'answer_text' => $text,
        ]);
    }

    private function fileAnswer(TestAttempt $attempt, TestQuestion $question): TestAnswer
    {
        Storage::disk('local')->put("test-answers/{$attempt->id}/solution.zip", 'zip');

        return TestAnswer::create([
            'test_attempt_id' => $attempt->id,
            'test_question_id' => $question->id,
            'file_path' => "test-answers/{$attempt->id}/solution.zip",
            'file_disk' => 'local',
            'file_original_name' => 'solution.zip',
            'file_mime_type' => 'application/zip',
            'file_size' => 3,
        ]);
    }

    private function submit(User $candidate, ApplicationTestAssignment $assignment)
    {
        return $this->withToken($this->tokenFor($candidate))
            ->postJson("/api/v1/tests/{$assignment->id}/submit", ['confirm' => true]);
    }

    private function grade(
        User $actor,
        TestAttempt $attempt,
        TestQuestion $question,
        float $points,
        ?string $note = null,
        string $method = 'put',
    ) {
        $payload = ['awarded_points' => $points, 'reviewer_note' => $note];

        return $this->withToken($this->tokenFor($actor))
            ->{$method.'Json'}($this->gradeUrl($attempt, $question), $payload);
    }

    private function gradeUrl(TestAttempt $attempt, TestQuestion $question): string
    {
        return "/api/v1/test-attempts/{$attempt->id}/answers/{$question->id}/grading";
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
