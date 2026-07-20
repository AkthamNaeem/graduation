<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ScreeningQuestionType;
use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\ApplicationScreeningAnswerService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ApplicationScreeningAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_job_without_questions_accepts_consent_only_and_writes_no_legacy_json(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();

        $response = $this->apply($candidate, $job, $cv, [])
            ->assertCreated()
            ->assertJsonPath('data.consent_to_share_profile', true)
            ->assertJsonCount(0, 'data.screening_answers');

        $this->assertDatabaseHas('job_applications', [
            'id' => $response->json('data.id'),
            'consent_to_share_profile' => true,
            'screening_answers' => null,
        ]);
    }

    public function test_cover_letter_is_trimmed_nullable_and_limited_to_ten_thousand_characters(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();

        $this->apply($candidate, $job, $cv, [], ['cover_letter' => '  Strong Laravel match.  '])
            ->assertCreated()
            ->assertJsonPath('data.cover_letter', 'Strong Laravel match.');

        [$candidate2, $job2, $cv2] = $this->applicationScenario();
        $this->apply($candidate2, $job2, $cv2, [], ['cover_letter' => '   '])
            ->assertCreated()
            ->assertJsonPath('data.cover_letter', null);

        [$candidate3, $job3, $cv3] = $this->applicationScenario();
        $this->apply($candidate3, $job3, $cv3, [], ['cover_letter' => str_repeat('x', 10001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cover_letter']);
    }

    public function test_consent_is_required_and_must_be_explicit_boolean_true(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $base = ['selected_cv_file_id' => $cv->id];
        $url = $this->applyUrl($job);
        $token = $this->tokenFor($candidate);

        $this->withToken($token)->postJson($url, $base)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent_to_share_profile']);
        $this->withToken($token)->postJson($url, $base + ['consent_to_share_profile' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent_to_share_profile']);
        $this->withToken($token)->postJson($url, $base + ['consent_to_share_profile' => 'yes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent_to_share_profile']);

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_short_text_answer_is_trimmed_and_snapshotted(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $question = $this->question($job, ScreeningQuestionType::SHORT_TEXT);

        $this->apply($candidate, $job, $cv, [['question_id' => $question->id, 'value' => '  Three years  ']])
            ->assertCreated()
            ->assertJsonPath('data.screening_answers.0.answer.value', 'Three years');

        $this->assertDatabaseHas('job_application_screening_answers', ['text_value' => 'Three years']);
    }

    public function test_required_text_rejects_blank_and_optional_text_can_be_omitted(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $required = $this->question($job, ScreeningQuestionType::SHORT_TEXT);

        $this->apply($candidate, $job, $cv, [['question_id' => $required->id, 'value' => '   ']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID');
        $this->assertNoApplicationWrites();

        [$candidate2, $job2, $cv2] = $this->applicationScenario();
        $this->question($job2, ScreeningQuestionType::SHORT_TEXT, required: false);
        $this->apply($candidate2, $job2, $cv2, [])->assertCreated()->assertJsonPath('data.screening_answers.0.answer.value', null);
    }

    public function test_long_text_accepts_valid_value_and_rejects_over_limit(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $question = $this->question($job, ScreeningQuestionType::LONG_TEXT);

        $this->apply($candidate, $job, $cv, [['question_id' => $question->id, 'value' => 'Detailed experience.']])
            ->assertCreated()
            ->assertJsonPath('data.screening_answers.0.answer.value', 'Detailed experience.');

        [$candidate2, $job2, $cv2] = $this->applicationScenario();
        $question2 = $this->question($job2, ScreeningQuestionType::LONG_TEXT);
        $this->apply($candidate2, $job2, $cv2, [['question_id' => $question2->id, 'value' => str_repeat('x', 10001)]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID');
    }

    public function test_number_accepts_integer_decimal_zero_and_negative_values(): void
    {
        foreach ([4, 2.5, 0, -3] as $value) {
            [$candidate, $job, $cv] = $this->applicationScenario();
            $question = $this->question($job, ScreeningQuestionType::NUMBER);

            $response = $this->apply($candidate, $job, $cv, [['question_id' => $question->id, 'value' => $value]])
                ->assertCreated();
            $this->assertEquals((float) $value, $response->json('data.screening_answers.0.answer.value'));
        }
    }

    public function test_number_rejects_non_numeric_string_options_and_out_of_range_values(): void
    {
        foreach ([
            ['value' => 'four'],
            ['value' => 4, 'selected_option_ids' => [1]],
            ['value' => 1000000001],
        ] as $answer) {
            [$candidate, $job, $cv] = $this->applicationScenario();
            $question = $this->question($job, ScreeningQuestionType::NUMBER);
            $answer['question_id'] = $question->id;

            $this->apply($candidate, $job, $cv, [$answer])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID');
        }
    }

    public function test_boolean_accepts_true_and_false_as_required_answers(): void
    {
        foreach ([true, false] as $value) {
            [$candidate, $job, $cv] = $this->applicationScenario();
            $question = $this->question($job, ScreeningQuestionType::BOOLEAN);

            $this->apply($candidate, $job, $cv, [['question_id' => $question->id, 'value' => $value]])
                ->assertCreated()
                ->assertJsonPath('data.screening_answers.0.answer.value', $value);
        }
    }

    public function test_boolean_rejects_null_string_and_selected_options(): void
    {
        foreach ([['value' => null], ['value' => 'false'], ['value' => true, 'selected_option_ids' => [1]]] as $answer) {
            [$candidate, $job, $cv] = $this->applicationScenario();
            $question = $this->question($job, ScreeningQuestionType::BOOLEAN);
            $answer['question_id'] = $question->id;

            $this->apply($candidate, $job, $cv, [$answer])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'APPLICATION_SCREENING_ANSWER_TYPE_INVALID');
        }
    }

    public function test_single_choice_accepts_exactly_one_owned_option(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $question = $this->question($job, ScreeningQuestionType::SINGLE_CHOICE, options: ['Morning', 'Evening']);
        $option = $question->options->firstOrFail();

        $this->apply($candidate, $job, $cv, [[
            'question_id' => $question->id,
            'selected_option_ids' => [$option->id],
        ]])->assertCreated()->assertJsonPath('data.screening_answers.0.answer.selected_options.0.option_text', 'Morning');
    }

    public function test_single_choice_rejects_zero_multiple_foreign_and_value_answers(): void
    {
        foreach (['empty', 'multiple', 'foreign', 'value'] as $case) {
            [$candidate, $job, $cv] = $this->applicationScenario();
            $question = $this->question($job, ScreeningQuestionType::SINGLE_CHOICE, options: ['Morning', 'Evening']);
            $otherQuestion = $this->question($job, ScreeningQuestionType::SINGLE_CHOICE, options: ['Other A', 'Other B']);
            $ids = $question->options->pluck('id')->all();
            $answer = match ($case) {
                'empty' => ['question_id' => $question->id, 'selected_option_ids' => []],
                'multiple' => ['question_id' => $question->id, 'selected_option_ids' => $ids],
                'foreign' => ['question_id' => $question->id, 'selected_option_ids' => [$otherQuestion->options->first()->id]],
                'value' => ['question_id' => $question->id, 'value' => 'Morning', 'selected_option_ids' => [$ids[0]]],
            };

            $this->apply($candidate, $job, $cv, [$answer])->assertUnprocessable();
        }
    }

    public function test_multiple_choice_accepts_many_and_rejects_duplicates_or_foreign_options(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $question = $this->question($job, ScreeningQuestionType::MULTIPLE_CHOICE, options: ['PHP', 'Laravel', 'MySQL']);
        $ids = $question->options->pluck('id')->all();

        $this->apply($candidate, $job, $cv, [['question_id' => $question->id, 'selected_option_ids' => [$ids[0], $ids[2]]]])
            ->assertCreated()
            ->assertJsonCount(2, 'data.screening_answers.0.answer.selected_options');

        [$candidate2, $job2, $cv2] = $this->applicationScenario();
        $question2 = $this->question($job2, ScreeningQuestionType::MULTIPLE_CHOICE, options: ['One', 'Two']);
        $id = $question2->options->first()->id;
        $this->apply($candidate2, $job2, $cv2, [['question_id' => $question2->id, 'selected_option_ids' => [$id, $id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['screening_answers.0.selected_option_ids.1']);
    }

    public function test_required_missing_duplicate_inactive_and_cross_job_questions_are_atomic_failures(): void
    {
        foreach (['missing', 'duplicate', 'inactive', 'cross_job'] as $case) {
            [$candidate, $job, $cv] = $this->applicationScenario();
            $question = $this->question($job, ScreeningQuestionType::SHORT_TEXT);
            $answers = match ($case) {
                'missing' => [],
                'duplicate' => [
                    ['question_id' => $question->id, 'value' => 'One'],
                    ['question_id' => $question->id, 'value' => 'Two'],
                ],
                'inactive' => tap([['question_id' => $question->id, 'value' => 'One']], fn () => $question->update(['is_active' => false])),
                'cross_job' => [['question_id' => $this->question(JobPosting::factory()->create(), ScreeningQuestionType::SHORT_TEXT)->id, 'value' => 'One']],
            };

            $this->apply($candidate, $job, $cv, $answers)->assertUnprocessable();
            $this->assertNoApplicationWrites();
        }
    }

    public function test_full_application_preserves_question_option_order_and_historical_text(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $number = $this->question($job, ScreeningQuestionType::NUMBER, sortOrder: 1);
        $choice = $this->question($job, ScreeningQuestionType::SINGLE_CHOICE, required: false, options: ['Morning', 'Evening'], sortOrder: 2);
        $selected = $choice->options->firstOrFail();

        $created = $this->apply($candidate, $job, $cv, [
            ['question_id' => $number->id, 'value' => 5],
            ['question_id' => $choice->id, 'selected_option_ids' => [$selected->id]],
        ])->assertCreated();
        $applicationId = $created->json('data.id');

        $number->update(['question_text' => 'Changed question text']);
        $selected->update(['option_text' => 'Changed option text']);
        $choice->update(['is_active' => false]);

        $details = $this->withToken($this->tokenFor($candidate))->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('data.screening_answers.0.question_text', 'Question number?')
            ->assertJsonPath('data.screening_answers.1.answer.selected_options.0.option_text', 'Morning');
        $details->assertJsonMissingPath('data.screening_answers.0.source_question_id');
        $this->assertDatabaseCount('job_application_screening_questions', 2);
        $this->assertDatabaseCount('job_application_screening_question_options', 2);
        $this->assertDatabaseCount('job_application_screening_answers', 2);
        $this->assertDatabaseCount('job_application_screening_answer_options', 1);
    }

    public function test_snapshot_failure_rolls_back_application_history_answers_and_notifications(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $this->question($job, ScreeningQuestionType::SHORT_TEXT);
        $this->app->bind(ApplicationScreeningAnswerService::class, static fn () => new class extends ApplicationScreeningAnswerService
        {
            public function persistSnapshots(JobApplication $application, array $plan): void
            {
                $application->screeningQuestionSnapshots()->create([
                    'source_question_id' => $plan[0]['question']->id,
                    'question_text' => 'Temporary snapshot',
                    'question_type' => ScreeningQuestionType::SHORT_TEXT,
                    'is_required' => true,
                    'sort_order' => 1,
                ]);

                throw new RuntimeException('Synthetic snapshot failure.');
            }
        });

        $this->apply($candidate, $job, $cv, [['question_id' => $job->screeningQuestions->first()->id, 'value' => 'Answer']])
            ->assertStatus(500);

        $this->assertNoApplicationWrites();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_legacy_application_without_consent_or_normalized_answers_reads_safely(): void
    {
        [$candidate, $job, $cv] = $this->applicationScenario();
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $candidate->jobSeekerProfile->id,
            'selected_cv_file_id' => $cv->id,
            'application_status_id' => ApplicationStatus::where('slug', 'submitted')->value('id'),
            'consent_to_share_profile' => false,
        ]);

        $this->withToken($this->tokenFor($candidate))->getJson("/api/v1/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.consent_to_share_profile', false)
            ->assertJsonCount(0, 'data.screening_answers');
    }

    /** @return array{User, JobPosting, CVFile} */
    private function applicationScenario(): array
    {
        $company = Company::factory()->create();
        $job = JobPosting::factory()->for($company)->create(['status' => 'open', 'published_at' => now()]);
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $candidate->id, 'headline' => 'Backend Engineer']);
        $candidate->load('jobSeekerProfile');
        $cv = CVFile::create([
            'user_id' => $candidate->id,
            'original_name' => 'candidate.pdf',
            'stored_path' => 'cv-files/'.Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
        ]);
        Storage::disk('local')->put($cv->stored_path, 'cv');

        return [$candidate, $job, $cv];
    }

    private function question(
        JobPosting $job,
        ScreeningQuestionType $type,
        bool $required = true,
        array $options = [],
        int $sortOrder = 1,
    ): JobScreeningQuestion {
        $question = JobScreeningQuestion::factory()->for($job)->create([
            'question_text' => "Question {$type->value}?",
            'question_type' => $type,
            'is_required' => $required,
            'sort_order' => $sortOrder,
        ]);
        foreach ($options as $index => $text) {
            $question->options()->create(['option_text' => $text, 'sort_order' => $index + 1]);
        }

        return $question->load('options');
    }

    private function apply(User $candidate, JobPosting $job, CVFile $cv, array $answers, array $extra = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($candidate))->postJson($this->applyUrl($job), [
            'selected_cv_file_id' => $cv->id,
            'consent_to_share_profile' => true,
            'screening_answers' => $answers,
            ...$extra,
        ]);
    }

    private function applyUrl(JobPosting $job): string
    {
        return "/api/v1/jobs/{$job->id}/applications";
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }

    private function assertNoApplicationWrites(): void
    {
        $this->assertDatabaseCount('job_applications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseCount('job_application_screening_questions', 0);
        $this->assertDatabaseCount('job_application_screening_question_options', 0);
        $this->assertDatabaseCount('job_application_screening_answers', 0);
        $this->assertDatabaseCount('job_application_screening_answer_options', 0);
    }
}
