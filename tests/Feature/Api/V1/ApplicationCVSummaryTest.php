<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['store'] === false
                && $request['text']['format']['type'] === 'json_schema';
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
        ]);
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

    private function employer(string $email, Company $company): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
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
}
