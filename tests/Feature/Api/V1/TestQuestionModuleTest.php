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
use App\Models\TestQuestion;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestQuestionModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_employer_test_creation_uses_own_company_and_catalog_is_company_scoped(): void
    {
        $firstCompany = $this->company('First Co.');
        $secondCompany = $this->company('Second Co.');
        $firstEmployer = $this->employer('first@example.com', $firstCompany);
        $secondEmployer = $this->employer('second@example.com', $secondCompany);
        $otherTest = $this->recruitmentTestFor($secondCompany, 'Other Company Test');

        $this->withToken($this->tokenFor($firstEmployer))->postJson('/api/v1/tests', [
            'company_id' => $secondCompany->id,
            'title' => 'Tampered Test',
            'duration_minutes' => 30,
        ])->assertUnprocessable()->assertJsonValidationErrors(['company_id']);

        $created = $this->withToken($this->tokenFor($firstEmployer))->postJson('/api/v1/tests', [
            'title' => 'Owned Test',
            'duration_minutes' => 30,
        ])->assertCreated()->assertJsonPath('data.company_id', $firstCompany->id);

        $this->withToken($this->tokenFor($firstEmployer))->getJson('/api/v1/tests')
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $created->json('data.id'));

        $this->withToken($this->tokenFor($firstEmployer))->getJson("/api/v1/tests/{$otherTest->id}")->assertForbidden();
        $this->withToken($this->tokenFor($firstEmployer))->patchJson("/api/v1/tests/{$otherTest->id}", ['title' => 'No'])->assertForbidden();
        $this->withToken($this->tokenFor($firstEmployer))->deleteJson("/api/v1/tests/{$otherTest->id}")->assertForbidden();
    }

    public function test_admin_can_create_and_manage_tests_for_explicit_companies(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $company = $this->company('Admin Selected Co.');

        $response = $this->withToken($this->tokenFor($admin))->postJson('/api/v1/admin/tests', [
            'company_id' => $company->id,
            'title' => 'Admin Test',
            'duration_minutes' => 45,
        ])->assertCreated()->assertJsonPath('data.company_id', $company->id);

        $this->withToken($this->tokenFor($admin))->patchJson('/api/v1/tests/'.$response->json('data.id'), [
            'title' => 'Updated Admin Test',
        ])->assertOk()->assertJsonPath('data.title', 'Updated Admin Test');
    }

    public function test_question_types_and_correct_answer_rules_are_validated(): void
    {
        [$employer, $test] = $this->ownedTestScenario();
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $this->questionPayload('short_text', []))
            ->assertCreated()->assertJsonCount(0, 'data.options');

        $validSingle = $this->questionPayload('single_choice', [
            ['option_text' => 'POST', 'order_index' => 1, 'is_correct' => false],
            ['option_text' => 'PUT', 'order_index' => 2, 'is_correct' => true],
        ], 2);
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $validSingle)->assertCreated();

        $noCorrect = $validSingle;
        $noCorrect['order_index'] = 3;
        $noCorrect['options'][1]['is_correct'] = false;
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $noCorrect)
            ->assertUnprocessable()->assertJsonValidationErrors(['options']);

        $twoCorrect = $validSingle;
        $twoCorrect['order_index'] = 3;
        $twoCorrect['options'][0]['is_correct'] = true;
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $twoCorrect)
            ->assertUnprocessable()->assertJsonValidationErrors(['options']);

        $validMultiple = $this->questionPayload('multiple_choice', [
            ['option_text' => 'A', 'order_index' => 1, 'is_correct' => true],
            ['option_text' => 'B', 'order_index' => 2, 'is_correct' => true],
        ], 3);
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $validMultiple)->assertCreated();

        $invalidMultiple = $validMultiple;
        $invalidMultiple['order_index'] = 4;
        $invalidMultiple['options'][0]['is_correct'] = false;
        $invalidMultiple['options'][1]['is_correct'] = false;
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $invalidMultiple)
            ->assertUnprocessable()->assertJsonValidationErrors(['options']);

        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $this->questionPayload('long_text', [
            ['option_text' => 'Not allowed', 'order_index' => 1, 'is_correct' => true],
        ], 4))->assertUnprocessable()->assertJsonValidationErrors(['options']);

        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $this->questionPayload('true_false', [
            ['option_text' => 'True', 'order_index' => 1, 'is_correct' => true],
            ['option_text' => 'False', 'order_index' => 2, 'is_correct' => false],
        ], 4))->assertCreated();

        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $this->questionPayload('long_text', [], 5))->assertCreated();
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $this->questionPayload('file_upload', [], 6))->assertCreated();
    }

    public function test_duplicate_option_text_and_order_are_rejected(): void
    {
        [$employer, $test] = $this->ownedTestScenario();
        $token = $this->tokenFor($employer);

        $duplicateText = $this->questionPayload('single_choice', [
            ['option_text' => 'PUT', 'order_index' => 1, 'is_correct' => true],
            ['option_text' => 'put', 'order_index' => 2, 'is_correct' => false],
        ]);
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $duplicateText)
            ->assertUnprocessable()->assertJsonValidationErrors(['options']);

        $duplicateOrder = $this->questionPayload('single_choice', [
            ['option_text' => 'PUT', 'order_index' => 1, 'is_correct' => true],
            ['option_text' => 'POST', 'order_index' => 1, 'is_correct' => false],
        ]);
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $duplicateOrder)
            ->assertUnprocessable()->assertJsonValidationErrors(['options']);
    }

    public function test_questions_can_be_read_updated_deleted_and_reordered_without_cross_test_idor(): void
    {
        [$employer, $test] = $this->ownedTestScenario();
        $otherTest = $this->recruitmentTestFor($test->company, 'Other Test');
        $first = $this->questionFor($test, 1, 'First');
        $second = $this->questionFor($test, 2, 'Second');
        $otherQuestion = $this->questionFor($otherTest, 1, 'Other');
        $token = $this->tokenFor($employer);

        $this->withToken($token)->getJson("/api/v1/tests/{$test->id}/questions")
            ->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $second->id);
        $this->withToken($token)->getJson("/api/v1/tests/{$test->id}/questions/{$first->id}")->assertOk();
        $this->withToken($token)->patchJson("/api/v1/tests/{$test->id}/questions/{$first->id}", ['question_text' => 'Updated'])
            ->assertOk()->assertJsonPath('data.question_text', 'Updated');
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/reorder", ['questions' => [
            ['question_id' => $first->id, 'order_index' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['questions']);
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/reorder", ['questions' => [
            ['question_id' => $first->id, 'order_index' => 2],
            ['question_id' => $second->id, 'order_index' => 1],
        ]])->assertOk()->assertJsonPath('data.0.id', $second->id);
        $this->withToken($token)->getJson("/api/v1/tests/{$test->id}/questions/{$otherQuestion->id}")->assertForbidden();
        $this->withToken($token)->deleteJson("/api/v1/tests/{$test->id}/questions/{$first->id}")->assertOk();
    }

    public function test_options_support_crud_reorder_and_hierarchy_validation(): void
    {
        [$employer, $test] = $this->ownedTestScenario();
        $question = $this->choiceQuestionFor($test);
        $otherQuestion = $this->choiceQuestionFor($test, 2);
        $token = $this->tokenFor($employer);

        $created = $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options", [
            'option_text' => 'PATCH', 'order_index' => 3, 'is_correct' => false,
        ])->assertCreated();
        $optionId = $created->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options/{$optionId}", [
            'option_text' => 'DELETE',
        ])->assertOk()->assertJsonPath('data.option_text', 'DELETE');

        $ids = $question->options()->pluck('id')->push($optionId)->unique()->values();
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options/reorder", ['options' => [
            ['option_id' => $ids[0], 'order_index' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['options']);
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options/reorder", ['options' => [
            ['option_id' => $ids[0], 'order_index' => 3],
            ['option_id' => $ids[1], 'order_index' => 2],
            ['option_id' => $ids[2], 'order_index' => 1],
        ]])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/tests/{$test->id}/questions/{$otherQuestion->id}/options/{$optionId}", [
            'option_text' => 'IDOR',
        ])->assertForbidden();
        $this->withToken($token)->deleteJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options/{$optionId}")->assertOk();
    }

    public function test_assigned_test_and_its_questions_and_options_are_immutable(): void
    {
        [$employer, $test] = $this->ownedTestScenario();
        $question = $this->choiceQuestionFor($test);
        $option = $question->options()->firstOrFail();
        $application = $this->applicationFor($test->company);
        ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
            'assigned_at' => now(),
        ]);
        $token = $this->tokenFor($employer);

        $this->withToken($token)->patchJson("/api/v1/tests/{$test->id}", ['title' => 'Blocked'])->assertConflict();
        $this->withToken($token)->deleteJson("/api/v1/tests/{$test->id}")->assertConflict();
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions", $this->questionPayload('short_text', [], 2))->assertConflict();
        $this->withToken($token)->patchJson("/api/v1/tests/{$test->id}/questions/{$question->id}", ['question_text' => 'Blocked'])->assertConflict();
        $this->withToken($token)->deleteJson("/api/v1/tests/{$test->id}/questions/{$question->id}")->assertConflict();
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/reorder", ['questions' => [
            ['question_id' => $question->id, 'order_index' => 1],
        ]])->assertConflict();
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options", [
            'option_text' => 'PATCH', 'order_index' => 3, 'is_correct' => false,
        ])->assertConflict();
        $this->withToken($token)->patchJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options/{$option->id}", ['option_text' => 'Blocked'])->assertConflict();
        $this->withToken($token)->deleteJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options/{$option->id}")->assertConflict();
        $this->withToken($token)->postJson("/api/v1/tests/{$test->id}/questions/{$question->id}/options/reorder", ['options' => [
            ['option_id' => $question->options[0]->id, 'order_index' => 2],
            ['option_id' => $question->options[1]->id, 'order_index' => 1],
        ]])->assertConflict();
    }

    public function test_cross_company_test_assignment_is_rejected(): void
    {
        $company = $this->company('Application Co.');
        $otherCompany = $this->company('Other Test Co.');
        $employer = $this->employer('owner@example.com', $company);
        $application = $this->applicationFor($company);
        $otherTest = $this->recruitmentTestFor($otherCompany, 'Foreign Test');

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/applications/{$application->id}/assign-test", [
            'test_id' => $otherTest->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['test_id']);
    }

    public function test_job_seeker_cannot_access_question_management_and_correct_answers_are_hidden(): void
    {
        [, $test] = $this->ownedTestScenario();
        $question = $this->choiceQuestionFor($test);
        $seeker = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $seeker->id]);

        $this->withToken($this->tokenFor($seeker))->getJson("/api/v1/tests/{$test->id}/questions")->assertForbidden();
        $this->withToken($this->tokenFor($seeker))->getJson("/api/v1/tests/{$test->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'TEST_CATALOG_FORBIDDEN');
        $this->assertNotNull($question);
    }

    private function company(string $name): Company
    {
        return Company::create(['name' => $name, 'approval_status' => 'approved']);
    }

    private function employer(string $email, Company $company): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    /** @return array{0: User, 1: RecruitmentTest} */
    private function ownedTestScenario(): array
    {
        $company = $this->company('Owned Test Co. '.Str::random(5));

        return [$this->employer(Str::random(5).'@example.com', $company), $this->recruitmentTestFor($company)];
    }

    private function recruitmentTestFor(Company $company, string $title = 'Backend Test'): RecruitmentTest
    {
        return RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => $title,
            'duration_minutes' => 60,
            'max_score' => 100,
            'passing_score' => null,
            'is_active' => true,
        ]);
    }

    private function questionFor(RecruitmentTest $test, int $order, string $text): TestQuestion
    {
        return $test->questions()->create([
            'question_text' => $text,
            'question_type' => 'short_text',
            'order_index' => $order,
            'points' => 2,
            'is_required' => true,
        ]);
    }

    private function choiceQuestionFor(RecruitmentTest $test, int $order = 1): TestQuestion
    {
        $question = $test->questions()->create([
            'question_text' => 'Which method?',
            'question_type' => 'single_choice',
            'order_index' => $order,
            'points' => 2,
            'is_required' => true,
        ]);
        $question->options()->createMany([
            ['option_text' => 'POST', 'order_index' => 1, 'is_correct' => false],
            ['option_text' => 'PUT', 'order_index' => 2, 'is_correct' => true],
        ]);

        return $question->load('options');
    }

    private function questionPayload(string $type, array $options, int $order = 1): array
    {
        return [
            'question_text' => 'Question '.$order,
            'question_type' => $type,
            'order_index' => $order,
            'points' => 2,
            'is_required' => true,
            'options' => $options,
        ];
    }

    private function applicationFor(Company $company): JobApplication
    {
        $seeker = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $seeker->id]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Role',
            'description' => 'Build APIs',
            'employment_type' => 'full_time',
            'experience_level' => 'mid',
            'status' => 'open',
        ]);

        return JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', 'under_review')->value('id'),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
