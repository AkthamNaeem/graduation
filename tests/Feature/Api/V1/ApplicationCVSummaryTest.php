<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Models\ApplicationSnapshot;
use App\Models\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\CVSummary\ApplicationCVSummaryService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationCVSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
        config([
            'cv_summary.provider' => 'openai',
            'cv_summary.openai.api_key' => 'test-key',
            'cv_summary.openai.model' => 'gpt-5-mini',
        ]);
    }

    public function test_company_manager_can_generate_and_reuse_job_specific_cv_summary(): void
    {
        [$company, $employer, $candidate, $application] = $this->scenario();
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse(), 200)]);

        $endpoint = "/api/v1/applications/{$application->id}/cv-summary";

        $this->withToken($this->tokenFor($employer))
            ->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.headline', 'Backend candidate aligned with Laravel API work')
            ->assertJsonPath('data.generation.provider', 'openai')
            ->assertJsonCount(1, 'data.strengths')
            ->assertJsonCount(1, 'data.gaps');

        $this->withToken($this->tokenFor($employer))
            ->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.headline', 'Backend candidate aligned with Laravel API work');

        $this->withToken($this->tokenFor($employer))
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.headline', 'Backend candidate aligned with Laravel API work');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['store'] === false
                && $request['text']['format']['type'] === 'json_schema'
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['required'] === [
                    'headline',
                    'summary',
                    'strengths',
                    'gaps',
                    'evidence',
                ];
        });

        $this->assertDatabaseHas('application_cv_summaries', [
            'job_application_id' => $application->id,
            'source_cv_file_id' => $application->selected_cv_file_id,
            'locale' => 'en',
            'provider' => 'openai',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'application.cv_summary_generated',
            'entity_id' => $application->id,
            'actor_user_id' => $employer->id,
        ]);

        $audit = AuditLog::query()->where('action', 'application.cv_summary_generated')->firstOrFail();
        $this->assertSame($application->id, $audit->metadata['application_id']);
        $this->assertSame('en', $audit->metadata['locale']);
        $this->assertSame('openai', $audit->metadata['provider']);
        $this->assertSame('gpt-5-mini', $audit->metadata['model']);
        $this->assertSame('1.0', $audit->metadata['prompt_version']);
        $this->assertSame(64, strlen($audit->metadata['input_hash']));
        $this->assertIsInt($audit->metadata['summary_id']);
        $this->assertStringNotContainsString('Backend Developer. Laravel REST APIs.', json_encode($audit->toArray()));
    }

    public function test_summary_for_snapshotted_application_uses_immutable_submission_profile_not_live_profile_or_source_parse(): void
    {
        [, $employer, $candidate, $application] = $this->scenario();
        ApplicationSnapshot::create([
            'job_application_id' => $application->id,
            'schema_version' => 1,
            'profile_snapshot' => [
                'identity' => [
                    'name' => 'Private Snapshot Name',
                    'email' => 'snapshot-private@example.com',
                    'phone' => '+963999999',
                    'headline' => 'Snapshot Backend Engineer',
                    'summary' => 'Immutable submission summary marker',
                ],
                'experiences' => [['job_title' => 'Snapshot Experience Marker']],
                'education' => [['institution' => 'Snapshot Education Marker']],
                'skills' => [['name' => 'Snapshot Skill Marker']],
            ],
            'application_answers_snapshot' => [],
            'source_cv_file_id' => $application->selected_cv_file_id,
            'cv_original_name' => 'audit-source.pdf',
            'cv_mime_type' => 'application/pdf',
            'cv_extension' => 'pdf',
            'cv_size_bytes' => 1,
            'cv_checksum_sha256' => str_repeat('a', 64),
            'cv_disk' => 'local',
            'cv_stored_path' => 'application-snapshots/audit-source.pdf',
            'origin' => ApplicationSnapshot::ORIGIN_SUBMISSION,
            'accuracy' => ApplicationSnapshot::ACCURACY_EXACT,
            'captured_at' => now(),
        ]);
        $candidate->jobSeekerProfile->update([
            'headline' => 'Changed Live Headline',
            'summary' => 'Changed live summary marker',
        ]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse(), 200)]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $this->assertStringContainsString('Immutable submission summary marker', $payload);
            $this->assertStringContainsString('Snapshot Experience Marker', $payload);
            $this->assertStringContainsString('Snapshot Education Marker', $payload);
            $this->assertStringContainsString('Snapshot Skill Marker', $payload);
            $this->assertStringNotContainsString('Changed Live Headline', $payload);
            $this->assertStringNotContainsString('Changed live summary marker', $payload);
            $this->assertStringNotContainsString('Backend Developer. Laravel REST APIs.', $payload);

            return true;
        });
    }

    public function test_job_seeker_and_other_company_employer_cannot_access_cv_summary(): void
    {
        [$company, $employer, $candidate, $application] = $this->scenario();
        $otherCompany = Company::create(['name' => 'Other Company', 'approval_status' => 'approved']);
        $otherEmployer = $this->employer('other-employer@example.com', $otherCompany);

        $this->withToken($this->tokenFor($candidate))
            ->getJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertForbidden();

        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertForbidden();

        $this->withToken($this->tokenFor($otherEmployer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_provider_failure_returns_stable_error_and_persists_nothing(): void
    {
        [$company, $employer, $candidate, $application] = $this->scenario();
        Http::fake(['api.openai.com/*' => Http::response([], 429)]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(503)
            ->assertJsonPath('code', 'CV_SUMMARY_RATE_LIMITED');

        $this->assertDatabaseCount('application_cv_summaries', 0);
        Http::assertSentCount(3);
    }

    public function test_force_true_regenerates_and_replaces_the_cached_summary(): void
    {
        [, $employer, , $application] = $this->scenario();
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse(), 200)]);
        $endpoint = "/api/v1/applications/{$application->id}/cv-summary";
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson($endpoint)->assertOk();
        $this->withToken($token)->postJson($endpoint, ['force' => true])->assertOk();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('application_cv_summaries', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_member_without_manage_applications_can_view_but_cannot_generate(): void
    {
        [$company, , , $application] = $this->scenario();
        $interviewer = $this->employer('interviewer@example.com', $company, CompanyRole::INTERVIEWER);

        $this->withToken($this->tokenFor($interviewer))
            ->getJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->withToken($this->tokenFor($interviewer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_invalid_structured_output_is_rejected_without_persistence(): void
    {
        [, $employer, , $application] = $this->scenario();
        $response = $this->openAIResponse();
        $response['output'][0]['content'][0]['text'] = json_encode([
            'headline' => 'Incomplete summary',
            'summary' => 'Missing required evidence.',
            'strengths' => [],
            'gaps' => [],
        ], JSON_THROW_ON_ERROR);
        Http::fake(['api.openai.com/*' => Http::response($response, 200)]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(502)
            ->assertJsonPath('code', 'CV_SUMMARY_INVALID_RESPONSE');

        $this->assertDatabaseCount('application_cv_summaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_separate_summaries_are_cached_for_english_and_arabic(): void
    {
        [, $employer, , $application] = $this->scenario();
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse(), 200)]);
        $endpoint = "/api/v1/applications/{$application->id}/cv-summary";
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson($endpoint)->assertJsonPath('data.locale', 'en');
        $this->withHeader('Accept-Language', 'ar')
            ->withToken($token)
            ->postJson($endpoint)
            ->assertJsonPath('data.locale', 'ar');

        Http::assertSentCount(2);
        $this->assertDatabaseHas('application_cv_summaries', [
            'job_application_id' => $application->id,
            'locale' => 'en',
        ]);
        $this->assertDatabaseHas('application_cv_summaries', [
            'job_application_id' => $application->id,
            'locale' => 'ar',
        ]);
    }

    public function test_changed_job_data_invalidates_the_cached_input_hash(): void
    {
        [, $employer, , $application] = $this->scenario();
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse(), 200)]);
        $endpoint = "/api/v1/applications/{$application->id}/cv-summary";
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson($endpoint)->assertOk();
        $originalHash = (string) $application->cvSummaries()->firstOrFail()->input_hash;
        $application->jobPosting()->update(['description' => 'Updated role description requiring event sourcing.']);
        $this->withToken($token)->postJson($endpoint)->assertOk();

        Http::assertSentCount(2);
        $this->assertNotSame($originalHash, (string) $application->cvSummaries()->firstOrFail()->input_hash);
    }

    public function test_insufficient_professional_source_returns_422_without_calling_openai(): void
    {
        [, $employer, , $application] = $this->scenario();
        $application->jobSeekerProfile()->update(['headline' => null, 'summary' => null]);
        $application->selectedCvFile->parsingResult()->delete();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(422)
            ->assertJsonPath('code', 'CV_SUMMARY_SOURCE_UNAVAILABLE');

        Http::assertNothingSent();
        $this->assertDatabaseCount('application_cv_summaries', 0);
    }

    public function test_sensitive_candidate_data_is_removed_from_the_openai_request(): void
    {
        [, $employer, $candidate, $application] = $this->scenario();
        $candidate->update(['name' => 'Sensitive Candidate', 'email' => 'private@example.test']);
        $application->jobSeekerProfile()->update(['phone' => '+963 944 123 456']);
        $application->selectedCvFile->parsingResult()->update([
            'reviewed_json' => [
                'full_name' => 'Sensitive Candidate',
                'contact' => [
                    'email' => 'private@example.test',
                    'phone' => '+963 944 123 456',
                ],
                'personal' => [
                    'birth_date' => '1990-01-01',
                    'nationality' => 'Sensitive Nationality',
                    'gender' => 'Sensitive Gender',
                    'religion' => 'Sensitive Religion',
                    'disability' => 'Sensitive Disability',
                ],
                'summary' => 'Sensitive Candidate builds APIs. Contact private@example.test.',
                'skills' => ['Laravel'],
            ],
        ]);
        Http::fake(['api.openai.com/*' => Http::response($this->openAIResponse(), 200)]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            foreach ([
                'Sensitive Candidate',
                'private@example.test',
                '+963 944 123 456',
                '1990-01-01',
                'Sensitive Nationality',
                'Sensitive Gender',
                'Sensitive Religion',
                'Sensitive Disability',
            ] as $sensitiveValue) {
                $this->assertStringNotContainsString($sensitiveValue, $payload);
            }

            $this->assertStringContainsString('untrusted data', $request['input'][0]['content'][0]['text']);

            return true;
        });
    }

    public function test_groq_provider_generates_caches_forces_and_audits_a_private_summary(): void
    {
        [, $employer, $candidate, $application] = $this->scenario();
        $this->configureGroq();
        $candidate->update(['name' => 'Sensitive Candidate', 'email' => 'private@example.test']);
        $application->jobSeekerProfile()->update(['phone' => '+963 944 123 456']);
        $application->selectedCvFile->parsingResult()->update([
            'reviewed_json' => [
                'full_name' => 'Sensitive Candidate',
                'contact' => [
                    'email' => 'private@example.test',
                    'phone' => '+963 944 123 456',
                ],
                'personal' => [
                    'birth_date' => '1990-01-01',
                    'nationality' => 'Sensitive Nationality',
                    'gender' => 'Sensitive Gender',
                    'religion' => 'Sensitive Religion',
                    'disability' => 'Sensitive Disability',
                ],
                'summary' => 'Sensitive Candidate builds Laravel APIs. Contact private@example.test.',
                'skills' => ['Laravel'],
            ],
        ]);
        Http::fake(['api.groq.com/*' => Http::response($this->groqResponse(), 200)]);
        $endpoint = "/api/v1/applications/{$application->id}/cv-summary";
        $token = $this->tokenFor($employer);

        $this->withToken($token)
            ->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.generation.provider', 'groq')
            ->assertJsonPath('data.generation.model', 'openai/gpt-oss-20b');
        $this->withToken($token)->postJson($endpoint)->assertOk();
        $this->withToken($token)->postJson($endpoint, ['force' => true])->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $payload = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $this->assertSame('https://api.groq.com/openai/v1/chat/completions', $request->url());
            $this->assertSame('Bearer groq-summary-test-key', $request->header('Authorization')[0] ?? null);
            $this->assertSame('json_schema', $request['response_format']['type']);
            $this->assertSame('application_cv_summary', $request['response_format']['json_schema']['name']);
            $this->assertTrue($request['response_format']['json_schema']['strict']);
            $this->assertArrayNotHasKey('store', $request->data());

            foreach ([
                'Sensitive Candidate',
                'private@example.test',
                '+963 944 123 456',
                '1990-01-01',
                'Sensitive Nationality',
                'Sensitive Gender',
                'Sensitive Religion',
                'Sensitive Disability',
            ] as $sensitiveValue) {
                $this->assertStringNotContainsString($sensitiveValue, $payload);
            }

            return true;
        });
        $this->assertDatabaseHas('application_cv_summaries', [
            'job_application_id' => $application->id,
            'provider' => 'groq',
            'model' => 'openai/gpt-oss-20b',
            'provider_request_id' => 'chatcmpl_cv_summary',
        ]);
        $this->assertDatabaseCount('application_cv_summaries', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $audit = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('groq', $audit->metadata['provider']);
        $this->assertSame('openai/gpt-oss-20b', $audit->metadata['model']);
    }

    public function test_provider_and_groq_model_changes_invalidate_the_cached_input_hash(): void
    {
        [, $employer, , $application] = $this->scenario();
        Http::fake([
            'api.openai.com/*' => Http::response($this->openAIResponse(), 200),
            'api.groq.com/*' => Http::response($this->groqResponse(), 200),
        ]);
        $endpoint = "/api/v1/applications/{$application->id}/cv-summary";
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson($endpoint)->assertOk();
        $openAIHash = (string) $application->cvSummaries()->firstOrFail()->input_hash;

        $this->configureGroq();
        $this->app->make(ApplicationCVSummaryService::class)->generate(
            $application->fresh(),
            $employer,
            'en',
        );
        $groqSummary = $application->cvSummaries()->firstOrFail();
        $this->assertSame('groq', $groqSummary->provider);
        $this->assertNotSame($openAIHash, $groqSummary->input_hash);

        config()->set('cv_summary.groq.model', 'openai/gpt-oss-120b');
        $this->app->make(ApplicationCVSummaryService::class)->generate(
            $application->fresh(),
            $employer,
            'en',
        );
        $changedModelSummary = $application->cvSummaries()->firstOrFail();
        $this->assertSame('openai/gpt-oss-120b', $changedModelSummary->model);
        $this->assertNotSame($groqSummary->input_hash, $changedModelSummary->input_hash);

        Http::assertSentCount(3);
        $this->assertDatabaseCount('application_cv_summaries', 1);
    }

    public function test_groq_json_object_fallback_is_locally_validated_and_persisted(): void
    {
        [, $employer, , $application] = $this->scenario();
        $this->configureGroq();
        Http::fakeSequence()
            ->push($this->groqJsonValidationFailure(), 400)
            ->push($this->groqResponse(), 200);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertOk()
            ->assertJsonPath('data.generation.provider', 'groq');

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame('json_schema', $requests[0][0]['response_format']['type']);
        $this->assertSame('json_object', $requests[1][0]['response_format']['type']);
        $this->assertArrayNotHasKey('json_schema', $requests[1][0]['response_format']);
        $this->assertDatabaseHas('application_cv_summaries', [
            'job_application_id' => $application->id,
            'provider' => 'groq',
            'provider_request_id' => 'chatcmpl_cv_summary',
        ]);
    }

    public function test_groq_rate_limit_never_persists_a_summary(): void
    {
        [, $employer, , $application] = $this->scenario();
        $this->configureGroq();
        Http::fake(['api.groq.com/*' => Http::response([], 429)]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(503)
            ->assertJsonPath('code', 'CV_SUMMARY_RATE_LIMITED');

        Http::assertSentCount(3);
        $this->assertDatabaseCount('application_cv_summaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('groqHttpFailureProvider')]
    public function test_groq_http_failures_never_persist_a_summary(int $status, string $code, int $attempts): void
    {
        [, $employer, , $application] = $this->scenario();
        $this->configureGroq();
        Http::fake(['api.groq.com/*' => Http::response([], $status)]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(503)
            ->assertJsonPath('code', $code);

        Http::assertSentCount($attempts);
        $this->assertDatabaseCount('application_cv_summaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function groqHttpFailureProvider(): array
    {
        return [
            'unauthorized' => [401, 'CV_SUMMARY_AUTHENTICATION_FAILED', 1],
            'forbidden' => [403, 'CV_SUMMARY_AUTHENTICATION_FAILED', 1],
            'provider unavailable' => [500, 'CV_SUMMARY_PROVIDER_UNAVAILABLE', 3],
        ];
    }

    #[DataProvider('groqInvalidSummaryProvider')]
    public function test_groq_invalid_summary_never_persists_a_record(string $content): void
    {
        [, $employer, , $application] = $this->scenario();
        $this->configureGroq();
        Http::fake(['api.groq.com/*' => Http::response([
            'id' => 'chatcmpl_invalid',
            'choices' => [['message' => ['content' => $content]]],
        ], 200)]);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(502)
            ->assertJsonPath('code', 'CV_SUMMARY_INVALID_RESPONSE');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('application_cv_summaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function groqInvalidSummaryProvider(): array
    {
        return [
            'invalid JSON' => ['{bad'],
            'contract mismatch' => ['{}'],
        ];
    }

    public function test_failed_groq_json_object_fallback_never_persists_or_attempts_a_third_request(): void
    {
        [, $employer, , $application] = $this->scenario();
        $this->configureGroq();
        Http::fakeSequence()
            ->push($this->groqJsonValidationFailure(), 400)
            ->push([
                'id' => 'chatcmpl_invalid_fallback',
                'choices' => [['message' => ['content' => '{bad']]],
            ], 200);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(502)
            ->assertJsonPath('code', 'CV_SUMMARY_INVALID_RESPONSE');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('application_cv_summaries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_groq_supports_separate_english_and_arabic_summaries(): void
    {
        [, $employer, , $application] = $this->scenario();
        $this->configureGroq();
        Http::fake(['api.groq.com/*' => Http::response($this->groqResponse(), 200)]);
        $endpoint = "/api/v1/applications/{$application->id}/cv-summary";
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson($endpoint)->assertJsonPath('data.locale', 'en');
        $this->withHeader('Accept-Language', 'ar')
            ->withToken($token)
            ->postJson($endpoint)
            ->assertJsonPath('data.locale', 'ar');

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertStringContainsString('Return the output in English', $requests[0][0]['messages'][0]['content']);
        $this->assertStringContainsString('Return the output in Arabic', $requests[1][0]['messages'][0]['content']);
        $this->assertDatabaseCount('application_cv_summaries', 2);
    }

    public function test_unknown_summary_provider_returns_a_stable_error_without_http_or_persistence(): void
    {
        [, $employer, , $application] = $this->scenario();
        config()->set('cv_summary.provider', 'unknown');
        Http::fake();

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/applications/{$application->id}/cv-summary")
            ->assertStatus(500)
            ->assertJsonPath('code', 'CV_SUMMARY_INVALID_PROVIDER');

        Http::assertNothingSent();
        $this->assertDatabaseCount('application_cv_summaries', 0);
    }

    /** @return array{Company, User, User, JobApplication} */
    private function scenario(): array
    {
        $company = Company::create(['name' => 'Acme Hiring', 'approval_status' => 'approved']);
        $employer = $this->employer('employer@example.com', $company);
        $candidate = User::factory()->create([
            'email' => 'candidate@example.com',
            'role' => UserRole::JOB_SEEKER,
        ]);
        $profile = JobSeekerProfile::create([
            'user_id' => $candidate->id,
            'headline' => 'Backend Developer',
            'summary' => 'Builds Laravel REST APIs.',
        ]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Developer',
            'description' => 'Build recruitment APIs with Laravel.',
            'requirements' => 'Laravel and Docker experience.',
            'employment_type' => 'full_time',
            'experience_level' => 'junior',
            'work_mode' => 'remote',
            'status' => 'open',
            'published_at' => now(),
        ]);
        $cv = CVFile::create([
            'user_id' => $candidate->id,
            'original_name' => 'candidate.pdf',
            'stored_path' => 'cv-files/candidate.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1000,
            'status' => 'parsed',
        ]);
        CVParsingResult::create([
            'cv_file_id' => $cv->id,
            'raw_text' => 'Backend Developer. Laravel REST APIs.',
            'parsed_json' => [
                'summary' => 'Backend developer focused on Laravel APIs.',
                'experience' => [],
                'education' => [],
                'skills' => ['Laravel', 'REST APIs'],
            ],
        ]);
        $status = ApplicationStatus::query()->where('slug', 'submitted')->firstOrFail();
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'selected_cv_file_id' => $cv->id,
            'application_status_id' => $status->id,
            'consent_to_share_profile' => true,
        ]);

        return [$company, $employer, $candidate->load('jobSeekerProfile'), $application];
    }

    private function employer(string $email, Company $company, ?CompanyRole $role = null): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'company_role' => $role,
        ]);

        return $user->load('employerProfile.company');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }

    private function configureGroq(string $model = 'openai/gpt-oss-20b'): void
    {
        config([
            'cv_summary.provider' => 'groq',
            'cv_summary.groq.api_key' => 'groq-summary-test-key',
            'cv_summary.groq.model' => $model,
            'cv_summary.groq.max_completion_tokens' => 2048,
            'cv_summary.groq.reasoning_effort' => 'low',
            'cv_summary.groq.temperature' => 0.2,
        ]);
    }

    /** @return array<string, mixed> */
    private function openAIResponse(): array
    {
        $summary = [
            'headline' => 'Backend candidate aligned with Laravel API work',
            'summary' => 'The candidate has explicit Laravel REST API experience relevant to the role.',
            'strengths' => ['Laravel REST API experience is explicitly evidenced.'],
            'gaps' => ['Docker experience is not evidenced in the supplied data.'],
            'evidence' => [[
                'statement' => 'Laravel REST API experience',
                'source' => 'Selected CV summary and verified profile',
            ]],
        ];

        return [
            'id' => 'resp_test_cv_summary',
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function groqResponse(): array
    {
        $openAIResponse = $this->openAIResponse();

        return [
            'id' => 'chatcmpl_cv_summary',
            'choices' => [[
                'message' => [
                    'content' => $openAIResponse['output'][0]['content'][0]['text'],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function groqJsonValidationFailure(): array
    {
        return [
            'error' => [
                'message' => 'Provider body must not be exposed.',
                'type' => 'invalid_request_error',
                'code' => 'json_validate_failed',
            ],
        ];
    }
}
