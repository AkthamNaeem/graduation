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
use App\Models\TestAnswer;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestAnswerModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_candidate_can_upsert_and_list_normalized_choice_and_text_answers(): void
    {
        $scenario = $this->scenario();
        $single = $this->question($scenario['test'], 'single_choice', 1);
        $short = $this->question($scenario['test'], 'short_text', 2);
        $assignment = $this->assignAndStart($scenario);
        $token = $this->tokenFor($scenario['candidate']);

        $this->withToken($token)->putJson($this->answerUrl($assignment['attempt'], $single), [
            'selected_option_ids' => [$single->options[1]->id],
        ])->assertOk()
            ->assertJsonPath('data.question_id', $single->id)
            ->assertJsonMissing(['is_correct']);

        $this->withToken($token)->patchJson($this->answerUrl($assignment['attempt'], $short), [
            'answer_text' => '  REST  ',
        ])->assertOk()->assertJsonPath('data.answer_text', 'REST');

        $this->withToken($token)->getJson("/api/v1/test-attempts/{$assignment['attempt']->id}/answers")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['file_path', 'file_disk', 'is_correct']);

        $this->assertDatabaseHas('test_answers', ['test_attempt_id' => $assignment['attempt']->id, 'answer_text' => 'REST']);
        $this->assertDatabaseHas('test_answer_options', ['test_option_id' => $single->options[1]->id]);
    }

    public function test_candidate_ownership_and_cross_hierarchy_idor_are_rejected(): void
    {
        $first = $this->scenario('first');
        $question = $this->question($first['test'], 'single_choice', 1);
        $firstAssignment = $this->assignAndStart($first);
        $second = $this->scenario('second');
        $foreignQuestion = $this->question($second['test'], 'single_choice', 1);
        $this->assignAndStart($second);
        $secondToken = $this->tokenFor($second['candidate']);

        $this->withToken($secondToken)->getJson("/api/v1/test-attempts/{$firstAssignment['attempt']->id}/answers")->assertForbidden();
        $this->withToken($secondToken)->putJson($this->answerUrl($firstAssignment['attempt'], $question), [
            'selected_option_ids' => [$question->options[0]->id],
        ])->assertForbidden();

        $this->withToken($this->tokenFor($first['candidate']))
            ->putJson($this->answerUrl($firstAssignment['attempt'], $foreignQuestion), [
                'selected_option_ids' => [$foreignQuestion->options[0]->id],
            ])->assertUnprocessable()->assertJsonValidationErrors(['question_id']);
    }

    public function test_choice_answers_enforce_cardinality_duplicates_and_option_ownership(): void
    {
        $scenario = $this->scenario();
        $single = $this->question($scenario['test'], 'single_choice', 1);
        $multiple = $this->question($scenario['test'], 'multiple_choice', 2);
        $other = $this->question($scenario['test'], 'single_choice', 3);
        $trueFalse = $this->question($scenario['test'], 'true_false', 4);
        $attempt = $this->assignAndStart($scenario)['attempt'];
        $token = $this->tokenFor($scenario['candidate']);

        $this->withToken($token)->putJson($this->answerUrl($attempt, $single), [
            'selected_option_ids' => $single->options->pluck('id')->all(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['selected_option_ids']);

        $this->withToken($token)->putJson($this->answerUrl($attempt, $single), [
            'selected_option_ids' => [$other->options[0]->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['selected_option_ids']);

        $this->withToken($token)->putJson($this->answerUrl($attempt, $multiple), [
            'selected_option_ids' => [$multiple->options[0]->id, $multiple->options[0]->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['selected_option_ids.1']);

        $this->withToken($token)->putJson($this->answerUrl($attempt, $multiple), [
            'selected_option_ids' => $multiple->options->pluck('id')->all(),
        ])->assertOk()->assertJsonCount(2, 'data.selected_options');

        $this->withToken($token)->putJson($this->answerUrl($attempt, $trueFalse), [
            'selected_option_ids' => [$trueFalse->options[0]->id],
        ])->assertOk()->assertJsonCount(1, 'data.selected_options');
    }

    public function test_text_answers_are_trimmed_limited_and_reject_other_answer_shapes(): void
    {
        $scenario = $this->scenario();
        $short = $this->question($scenario['test'], 'short_text', 1);
        $long = $this->question($scenario['test'], 'long_text', 2);
        $attempt = $this->assignAndStart($scenario)['attempt'];
        $token = $this->tokenFor($scenario['candidate']);

        $this->withToken($token)->putJson($this->answerUrl($attempt, $short), ['answer_text' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['answer_text']);
        $this->withToken($token)->putJson($this->answerUrl($attempt, $short), ['answer_text' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['answer_text']);
        $this->withToken($token)->putJson($this->answerUrl($attempt, $long), ['answer_text' => str_repeat('x', 10001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['answer_text']);
        $this->withToken($token)->putJson($this->answerUrl($attempt, $short), ['selected_option_ids' => [1]])
            ->assertUnprocessable()->assertJsonValidationErrors(['answer']);
    }

    public function test_bulk_save_is_atomic_and_upserts_existing_answers(): void
    {
        $scenario = $this->scenario();
        $short = $this->question($scenario['test'], 'short_text', 1);
        $single = $this->question($scenario['test'], 'single_choice', 2);
        $foreign = $this->question($scenario['test'], 'single_choice', 3);
        $attempt = $this->assignAndStart($scenario)['attempt'];
        $token = $this->tokenFor($scenario['candidate']);
        $url = "/api/v1/test-attempts/{$attempt->id}/answers/bulk";

        $this->withToken($token)->postJson($url, ['answers' => [
            ['question_id' => $short->id, 'answer_text' => 'Saved then rolled back'],
            ['question_id' => $single->id, 'selected_option_ids' => [$foreign->options[0]->id]],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('test_answers', 0);

        $this->withToken($token)->postJson($url, ['answers' => [
            ['question_id' => $short->id, 'answer_text' => 'First'],
            ['question_id' => $single->id, 'selected_option_ids' => [$single->options[0]->id]],
        ]])->assertOk()->assertJsonCount(2, 'data');

        $this->withToken($token)->postJson($url, ['answers' => [
            ['question_id' => $short->id, 'answer_text' => 'Updated'],
        ]])->assertOk();
        $this->assertDatabaseCount('test_answers', 2);
        $this->assertDatabaseHas('test_answers', ['test_question_id' => $short->id, 'answer_text' => 'Updated']);
    }

    public function test_file_answer_upload_replace_delete_and_download_are_private_and_authorized(): void
    {
        Storage::fake('local');
        $scenario = $this->scenario();
        $fileQuestion = $this->question($scenario['test'], 'file_upload', 1);
        $attempt = $this->assignAndStart($scenario)['attempt'];
        $token = $this->tokenFor($scenario['candidate']);
        $url = "/api/v1/test-attempts/{$attempt->id}/answers/{$fileQuestion->id}/file";

        $this->withToken($token)->post($url, ['answer_file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertUnprocessable();
        $this->withToken($token)->post($url, ['answer_file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')])
            ->assertUnprocessable();

        $this->withToken($token)->post($url, ['answer_file' => UploadedFile::fake()->create('solution.pdf', 20, 'application/pdf')])
            ->assertOk()->assertJsonPath('data.file.original_name', 'solution.pdf')
            ->assertJsonMissing(['file_path', 'file_disk']);
        $first = TestAnswer::firstOrFail();
        Storage::disk('local')->assertExists($first->file_path);

        $this->withToken($token)->post($url, ['answer_file' => UploadedFile::fake()->create('replacement.pdf', 30, 'application/pdf')])->assertOk();
        $replacement = TestAnswer::firstOrFail();
        Storage::disk('local')->assertMissing($first->file_path);
        Storage::disk('local')->assertExists($replacement->file_path);

        $this->withToken($token)->get($url)->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->withToken($this->tokenFor($scenario['employer']))->get($url)->assertOk();
        $outsider = $this->scenario('outsider')['candidate'];
        $this->withToken($this->tokenFor($outsider))->get($url)->assertForbidden();
        $foreignEmployer = $this->scenario('foreign-file-employer')['employer'];
        $this->withToken($this->tokenFor($foreignEmployer))->get($url)->assertForbidden();

        $this->withToken($token)->deleteJson($this->answerUrl($attempt, $fileQuestion))->assertOk();
        Storage::disk('local')->assertMissing($replacement->file_path);
    }

    public function test_submit_requires_complete_required_answers_and_updates_workflow_once(): void
    {
        $scenario = $this->scenario();
        $single = $this->question($scenario['test'], 'single_choice', 1, true);
        $short = $this->question($scenario['test'], 'short_text', 2, true);
        $this->question($scenario['test'], 'file_upload', 3, false);
        $data = $this->assignAndStart($scenario);
        $attempt = $data['attempt'];
        $token = $this->tokenFor($scenario['candidate']);

        $this->withToken($token)->postJson("/api/v1/tests/{$data['assignment']->id}/submit", ['confirm' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Some required questions have not been answered.')
            ->assertJsonPath('errors.unanswered_question_ids', [$single->id, $short->id]);

        $this->withToken($token)->putJson($this->answerUrl($attempt, $single), ['selected_option_ids' => [$single->options[0]->id]])->assertOk();
        $this->withToken($token)->putJson($this->answerUrl($attempt, $short), ['answer_text' => 'Complete'])->assertOk();

        $historyBefore = $scenario['application']->statusHistory()->count();
        $this->withToken($token)->postJson("/api/v1/tests/{$data['assignment']->id}/submit", ['confirm' => true])
            ->assertOk()->assertJsonCount(2, 'data.answers')->assertJsonPath('data.score', null);

        $scenario['application']->refresh();
        $this->assertSame('test_completed', $scenario['application']->applicationStatus->slug);
        $this->assertSame($historyBefore + 1, $scenario['application']->statusHistory()->count());
        $this->assertNull($attempt->fresh()->answers);
        $this->assertFalse(\Schema::hasColumn('job_applications', 'score'));
    }

    public function test_answers_and_submit_are_immutable_after_submission(): void
    {
        $scenario = $this->scenario();
        $short = $this->question($scenario['test'], 'short_text', 1);
        $data = $this->assignAndStart($scenario);
        $token = $this->tokenFor($scenario['candidate']);
        $this->withToken($token)->putJson($this->answerUrl($data['attempt'], $short), ['answer_text' => 'Done'])->assertOk();
        $this->withToken($token)->postJson("/api/v1/tests/{$data['assignment']->id}/submit", ['confirm' => true])->assertOk();

        $this->withToken($token)->patchJson($this->answerUrl($data['attempt'], $short), ['answer_text' => 'Changed'])->assertStatus(409);
        $this->withToken($token)->deleteJson($this->answerUrl($data['attempt'], $short))->assertStatus(409);
        $this->withToken($token)->postJson("/api/v1/test-attempts/{$data['attempt']->id}/answers/bulk", [
            'answers' => [['question_id' => $short->id, 'answer_text' => 'Changed']],
        ])->assertStatus(409);
        $this->withToken($token)->postJson("/api/v1/tests/{$data['assignment']->id}/submit", ['confirm' => true])->assertStatus(409);
    }

    public function test_employer_and_admin_can_read_but_cannot_modify_candidate_answers(): void
    {
        $scenario = $this->scenario();
        $short = $this->question($scenario['test'], 'short_text', 1);
        $data = $this->assignAndStart($scenario);
        $candidateToken = $this->tokenFor($scenario['candidate']);
        $this->withToken($candidateToken)->putJson($this->answerUrl($data['attempt'], $short), ['answer_text' => 'Private response'])->assertOk();
        $url = "/api/v1/test-attempts/{$data['attempt']->id}/answers";

        $this->withToken($this->tokenFor($scenario['employer']))->getJson($url)->assertOk()->assertJsonPath('data.0.answer_text', 'Private response');
        $this->withToken($this->tokenFor($scenario['employer']))->patchJson($this->answerUrl($data['attempt'], $short), ['answer_text' => 'Tamper'])->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->tokenFor($admin))->getJson($url)->assertOk();

        $foreign = $this->scenario('foreign-employer')['employer'];
        $this->withToken($this->tokenFor($foreign))->getJson($url)->assertForbidden();
    }

    public function test_structured_legacy_submit_payload_is_normalized_without_json_writes(): void
    {
        $scenario = $this->scenario();
        $single = $this->question($scenario['test'], 'single_choice', 1);
        $data = $this->assignAndStart($scenario);

        $this->withToken($this->tokenFor($scenario['candidate']))
            ->postJson("/api/v1/tests/{$data['assignment']->id}/submit", ['answers' => [[
                'question_id' => $single->id,
                'selected_option_ids' => [$single->options[1]->id],
            ]]])->assertOk()->assertJsonPath('data.answers.0.question_id', $single->id);

        $this->assertDatabaseHas('test_answers', ['test_attempt_id' => $data['attempt']->id, 'test_question_id' => $single->id]);
        $this->assertNull($data['attempt']->fresh()->answers);
    }

    private function scenario(string $suffix = 'base'): array
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
            'max_score' => 100,
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

    private function question(RecruitmentTest $test, string $type, int $order, bool $required = true): TestQuestion
    {
        $question = $test->questions()->create([
            'question_text' => "Question {$order}",
            'question_type' => $type,
            'order_index' => $order,
            'points' => 10,
            'is_required' => $required,
        ]);
        if (in_array($type, ['single_choice', 'multiple_choice', 'true_false'], true)) {
            $question->options()->createMany($type === 'true_false' ? [
                ['option_text' => 'True', 'order_index' => 1, 'is_correct' => true],
                ['option_text' => 'False', 'order_index' => 2, 'is_correct' => false],
            ] : [
                ['option_text' => "First {$order}", 'order_index' => 1, 'is_correct' => true],
                ['option_text' => "Second {$order}", 'order_index' => 2, 'is_correct' => $type === 'multiple_choice'],
            ]);
        }

        return $question->load('options');
    }

    private function answerUrl(TestAttempt $attempt, TestQuestion $question): string
    {
        return "/api/v1/test-attempts/{$attempt->id}/answers/{$question->id}";
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
