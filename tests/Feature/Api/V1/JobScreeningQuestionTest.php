<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobScreeningQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_short_text_question_with_safe_response(): void
    {
        [$owner, $job] = $this->ownerAndJob();

        $this->withToken($this->tokenFor($owner))
            ->postJson($this->questionsUrl($job), [
                'question_text' => '  Describe your Laravel experience.  ',
                'question_type' => 'short_text',
                'is_required' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.question_text', 'Describe your Laravel experience.')
            ->assertJsonPath('data.question_type', 'short_text')
            ->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonCount(0, 'data.options')
            ->assertJsonMissingPath('data.created_by_user_id')
            ->assertJsonMissingPath('data.created_at');
    }

    public function test_owner_creates_long_text_question(): void
    {
        [$owner, $job] = $this->ownerAndJob();

        $this->createQuestionThroughApi($owner, $job, 'long_text')
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'long_text');
    }

    public function test_owner_creates_number_question(): void
    {
        [$owner, $job] = $this->ownerAndJob();

        $this->createQuestionThroughApi($owner, $job, 'number')
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'number');
    }

    public function test_owner_creates_boolean_question_without_synthetic_options(): void
    {
        [$owner, $job] = $this->ownerAndJob();

        $this->createQuestionThroughApi($owner, $job, 'boolean')
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'boolean')
            ->assertJsonCount(0, 'data.options');
    }

    public function test_owner_creates_single_choice_with_ordered_options(): void
    {
        [$owner, $job] = $this->ownerAndJob();

        $this->withToken($this->tokenFor($owner))
            ->postJson($this->questionsUrl($job), $this->choicePayload('single_choice'))
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'single_choice')
            ->assertJsonPath('data.options.0.option_text', 'Morning')
            ->assertJsonPath('data.options.1.option_text', 'Evening');
    }

    public function test_owner_creates_multiple_choice_question(): void
    {
        [$owner, $job] = $this->ownerAndJob();

        $this->withToken($this->tokenFor($owner))
            ->postJson($this->questionsUrl($job), $this->choicePayload('multiple_choice'))
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'multiple_choice');
    }

    public function test_creation_rejects_invalid_type_and_blank_text(): void
    {
        [$owner, $job] = $this->ownerAndJob();
        $token = $this->tokenFor($owner);

        $this->withToken($token)->postJson($this->questionsUrl($job), [
            'question_text' => 'Question?',
            'question_type' => 'unsupported',
        ])->assertUnprocessable()->assertJsonValidationErrors(['question_type']);

        $this->withToken($token)->postJson($this->questionsUrl($job), [
            'question_text' => '   ',
            'question_type' => 'short_text',
        ])->assertUnprocessable()->assertJsonValidationErrors(['question_text']);
    }

    public function test_choice_requires_two_non_duplicate_options(): void
    {
        [$owner, $job] = $this->ownerAndJob();
        $token = $this->tokenFor($owner);

        $this->withToken($token)->postJson($this->questionsUrl($job), [
            'question_text' => 'Schedule?',
            'question_type' => 'single_choice',
            'options' => [['option_text' => 'Morning']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['options']);

        $this->withToken($token)->postJson($this->questionsUrl($job), [
            'question_text' => 'Schedule?',
            'question_type' => 'single_choice',
            'options' => [['option_text' => ' Morning '], ['option_text' => 'morning']],
        ])->assertUnprocessable()->assertJsonPath('code', 'JOB_SCREENING_QUESTION_DUPLICATE_OPTION');
    }

    public function test_non_choice_question_rejects_options(): void
    {
        [$owner, $job] = $this->ownerAndJob();

        $this->withToken($this->tokenFor($owner))->postJson($this->questionsUrl($job), [
            'question_text' => 'Experience?',
            'question_type' => 'short_text',
            'options' => [['option_text' => 'One'], ['option_text' => 'Two']],
        ])->assertUnprocessable()->assertJsonPath('code', 'JOB_SCREENING_QUESTION_OPTIONS_NOT_ALLOWED');
    }

    public function test_active_question_limit_is_enforced_under_job_lock(): void
    {
        [$owner, $job] = $this->ownerAndJob();
        JobScreeningQuestion::factory()->count(50)->for($job)->create();

        $this->createQuestionThroughApi($owner, $job, 'short_text')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'JOB_SCREENING_QUESTION_LIMIT_REACHED');
    }

    public function test_cross_company_employer_job_seeker_and_pending_company_are_denied(): void
    {
        [$owner, $job] = $this->ownerAndJob();
        $otherEmployer = $this->employer(Company::factory()->create());
        $jobSeeker = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $pendingCompany = Company::factory()->create(['approval_status' => 'pending']);
        $pendingEmployer = $this->employer($pendingCompany);
        $pendingJob = JobPosting::factory()->for($pendingCompany)->create();
        $payload = ['question_text' => 'Question?', 'question_type' => 'short_text'];

        $this->withToken($this->tokenFor($otherEmployer))->postJson($this->questionsUrl($job), $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($jobSeeker))->postJson($this->questionsUrl($job), $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($pendingEmployer))->postJson($this->questionsUrl($pendingJob), $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_PENDING');

        $this->assertSame($owner->employerProfile->company_id, $job->company_id);
    }

    public function test_update_supports_text_to_choice_and_choice_to_text(): void
    {
        [$owner, $job] = $this->ownerAndJob();
        $question = JobScreeningQuestion::factory()->for($job)->create();
        $token = $this->tokenFor($owner);
        $url = $this->questionUrl($job, $question);

        $this->withToken($token)->putJson($url, ['question_type' => 'single_choice'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'JOB_SCREENING_QUESTION_OPTIONS_REQUIRED');

        $this->withToken($token)->putJson($url, [
            'question_text' => 'Preferred schedule?',
            'question_type' => 'single_choice',
            'is_required' => true,
            'options' => [['option_text' => 'Morning'], ['option_text' => 'Evening']],
        ])->assertOk()->assertJsonCount(2, 'data.options');

        $this->withToken($token)->putJson($url, ['question_type' => 'long_text'])
            ->assertOk()
            ->assertJsonCount(0, 'data.options');
        $this->assertDatabaseCount('job_screening_question_options', 0);
    }

    public function test_disable_hides_question_from_public_and_job_details_but_keeps_row(): void
    {
        [$owner, $job] = $this->ownerAndJob(['status' => 'open', 'published_at' => now()]);
        $active = JobScreeningQuestion::factory()->for($job)->create(['sort_order' => 2]);
        JobScreeningQuestion::factory()->for($job)->create(['sort_order' => 1, 'is_active' => false]);

        $this->getJson($this->questionsUrl($job))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->withToken($this->tokenFor($owner))->deleteJson($this->questionUrl($job, $active))->assertOk();

        $this->getJson($this->questionsUrl($job))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/jobs/{$job->id}")->assertOk()->assertJsonCount(0, 'data.screening_questions');
        $this->assertDatabaseHas('job_screening_questions', ['id' => $active->id, 'is_active' => false]);
    }

    public function test_public_questions_are_ordered_and_hide_internal_fields(): void
    {
        [, $job] = $this->ownerAndJob(['status' => 'open', 'published_at' => now()]);
        $later = JobScreeningQuestion::factory()->for($job)->create(['sort_order' => 5]);
        $earlier = JobScreeningQuestion::factory()->for($job)->create(['sort_order' => 1]);

        $response = $this->getJson($this->questionsUrl($job))
            ->assertOk()
            ->assertJsonPath('data.0.id', $earlier->id)
            ->assertJsonPath('data.1.id', $later->id);

        foreach (['created_by_user_id', 'created_at', 'updated_at', 'job_posting_id'] as $field) {
            $response->assertJsonMissingPath("data.0.{$field}");
        }
    }

    public function test_cross_job_question_update_is_not_found_and_closed_job_is_immutable(): void
    {
        [$owner, $job] = $this->ownerAndJob();
        $otherJob = JobPosting::factory()->for($job->company)->create();
        $question = JobScreeningQuestion::factory()->for($otherJob)->create();
        $token = $this->tokenFor($owner);

        $this->withToken($token)->putJson($this->questionUrl($job, $question), ['question_text' => 'Changed?'])
            ->assertNotFound()
            ->assertJsonPath('code', 'JOB_SCREENING_QUESTION_NOT_FOUND');

        $job->update(['status' => 'closed']);
        $this->withToken($token)->postJson($this->questionsUrl($job), [
            'question_text' => 'New?',
            'question_type' => 'short_text',
        ])->assertConflict()->assertJsonPath('code', 'JOB_SCREENING_QUESTION_JOB_CLOSED');
    }

    /** @return array{User, JobPosting} */
    private function ownerAndJob(array $jobOverrides = []): array
    {
        $company = Company::factory()->create();

        return [$this->employer($company), JobPosting::factory()->for($company)->create($jobOverrides)];
    }

    private function employer(Company $company): User
    {
        $user = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    private function createQuestionThroughApi(User $owner, JobPosting $job, string $type)
    {
        return $this->withToken($this->tokenFor($owner))->postJson($this->questionsUrl($job), [
            'question_text' => "A {$type} question?",
            'question_type' => $type,
        ]);
    }

    /** @return array<string, mixed> */
    private function choicePayload(string $type): array
    {
        return [
            'question_text' => 'Preferred schedule?',
            'question_type' => $type,
            'options' => [
                ['option_text' => 'Evening', 'sort_order' => 2],
                ['option_text' => 'Morning', 'sort_order' => 1],
            ],
        ];
    }

    private function questionsUrl(JobPosting $job): string
    {
        return "/api/v1/jobs/{$job->id}/screening-questions";
    }

    private function questionUrl(JobPosting $job, JobScreeningQuestion $question): string
    {
        return $this->questionsUrl($job)."/{$question->id}";
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
