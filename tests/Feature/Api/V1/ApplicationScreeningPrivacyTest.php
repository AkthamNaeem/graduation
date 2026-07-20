<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationScreeningPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_only_candidate_and_owning_employer_can_read_historical_answers(): void
    {
        [$owner, $candidate, $job, $cv, $questionId] = $this->scenario();
        $applicationId = $this->apply($candidate, $job, $cv, $questionId);

        $candidateDetail = $this->as($candidate)->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('data.screening_answers.0.answer.value', 'Private candidate answer');
        $candidateDetail->assertJsonMissingPath('data.screening_answers.0.source_question_id');
        $candidateDetail->assertJsonMissingPath('data.screening_answers.0.id');

        $this->as($owner)->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonPath('data.cover_letter', 'Private cover letter')
            ->assertJsonPath('data.screening_answers.0.question_text', 'Private screening question?');

        $otherCandidate = $this->candidate('other-candidate@example.com');
        $otherEmployer = $this->employer(Company::factory()->create(), 'other-employer@example.com');
        $this->as($otherCandidate)->getJson("/api/v1/applications/{$applicationId}")->assertForbidden();
        $this->as($otherEmployer)->getJson("/api/v1/applications/{$applicationId}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson("/api/v1/applications/{$applicationId}")->assertUnauthorized();
    }

    public function test_application_lists_remain_summary_only_while_detail_eager_loads_answers(): void
    {
        [$owner, $candidate, $job, $cv, $questionId] = $this->scenario();
        $applicationId = $this->apply($candidate, $job, $cv, $questionId);

        $this->as($candidate)->getJson('/api/v1/applications/my')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $applicationId)
            ->assertJsonCount(0, 'data.data.0.screening_answers');

        $this->as($owner)->getJson("/api/v1/jobs/{$job->id}/applications")
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $applicationId)
            ->assertJsonCount(0, 'data.data.0.screening_answers');

        $this->as($owner)->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.screening_answers');
    }

    public function test_audit_and_notifications_do_not_store_question_answer_or_cover_letter_text(): void
    {
        [$owner, $candidate, $job, $cv, $questionId] = $this->scenario();
        $this->apply($candidate, $job, $cv, $questionId);

        $auditPayload = AuditLog::query()->get()->toJson();
        $notificationPayload = Notification::query()->get()->toJson();

        foreach (['Private screening question?', 'Private candidate answer', 'Private cover letter'] as $privateText) {
            $this->assertStringNotContainsString($privateText, $auditPayload);
            $this->assertStringNotContainsString($privateText, $notificationPayload);
        }
    }

    /** @return array{User, User, JobPosting, CVFile, int} */
    private function scenario(): array
    {
        $company = Company::factory()->create();
        $owner = $this->employer($company, 'owner@example.com');
        $candidate = $this->candidate('candidate@example.com');
        $job = JobPosting::factory()->for($company)->create(['status' => 'open', 'published_at' => now()]);
        $cv = $this->cv($candidate);

        $response = $this->as($owner)->postJson("/api/v1/jobs/{$job->id}/screening-questions", [
            'question_text' => 'Private screening question?',
            'question_type' => 'short_text',
            'is_required' => true,
        ])->assertCreated();

        return [$owner, $candidate, $job, $cv, (int) $response->json('data.id')];
    }

    private function apply(User $candidate, JobPosting $job, CVFile $cv, int $questionId): int
    {
        return (int) $this->as($candidate)->postJson("/api/v1/jobs/{$job->id}/applications", [
            'selected_cv_file_id' => $cv->id,
            'cover_letter' => 'Private cover letter',
            'consent_to_share_profile' => true,
            'screening_answers' => [[
                'question_id' => $questionId,
                'value' => 'Private candidate answer',
            ]],
        ])->assertCreated()->json('data.id');
    }

    private function employer(Company $company, string $email): User
    {
        $user = User::factory()->create(['role' => UserRole::EMPLOYER, 'email' => $email]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    private function candidate(string $email): User
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER, 'email' => $email]);
        JobSeekerProfile::create(['user_id' => $user->id, 'headline' => 'Engineer']);

        return $user->load('jobSeekerProfile');
    }

    private function cv(User $candidate): CVFile
    {
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

        return $cv;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($user));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}
