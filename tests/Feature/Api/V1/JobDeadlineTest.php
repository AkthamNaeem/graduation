<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        Carbon::setTestNow('2026-08-01T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_create_update_remove_and_validate_application_deadline(): void
    {
        [$employer, $company] = $this->employer();
        $token = $this->tokenFor($employer);
        $payload = $this->payload();

        $withoutDeadline = $this->withToken($token)->postJson('/api/v1/jobs', $payload)
            ->assertCreated()->assertJsonPath('data.application_deadline', null)
            ->assertJsonPath('data.has_application_deadline', false);
        $jobId = $withoutDeadline->json('data.id');

        $this->withToken($token)->putJson("/api/v1/jobs/{$jobId}", [
            'application_deadline' => '2026-08-15T20:00:00Z',
        ])->assertOk()->assertJsonPath('data.has_application_deadline', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'job.application_deadline_changed', 'entity_id' => $jobId]);
        $this->withToken($token)->putJson("/api/v1/jobs/{$jobId}", ['application_deadline' => null])
            ->assertOk()->assertJsonPath('data.application_deadline', null);

        $this->withToken($token)->postJson('/api/v1/jobs', [
            ...$payload,
            'application_deadline' => '2026-07-31T20:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors(['application_deadline']);

        $this->assertSame('approved', $company->approval_status);
    }

    public function test_apply_is_allowed_at_deadline_and_blocked_after_without_side_effects(): void
    {
        [, $company] = $this->employer();
        $candidate = $this->candidate();
        $token = $this->tokenFor($candidate);
        $atBoundary = $this->job($company, now());

        $this->withToken($token)->postJson("/api/v1/jobs/{$atBoundary->id}/applications", $this->applicationPayload($candidate))
            ->assertCreated();

        $passed = $this->job($company, now()->subSecond(), 'Passed Deadline Role');
        $applicationCount = \DB::table('job_applications')->count();
        $historyCount = \DB::table('application_status_histories')->count();
        $notificationCount = \DB::table('notifications')->count();

        $this->withToken($token)->postJson("/api/v1/jobs/{$passed->id}/applications", $this->applicationPayload($candidate))
            ->assertStatus(409)
            ->assertJsonPath('code', 'JOB_APPLICATION_DEADLINE_PASSED');

        $this->assertDatabaseCount('job_applications', $applicationCount);
        $this->assertDatabaseCount('application_status_histories', $historyCount);
        $this->assertDatabaseCount('notifications', $notificationCount);
        $this->assertSame('open', $passed->refresh()->status);
    }

    public function test_expired_open_job_remains_public_and_filterable_but_can_apply_changes_with_extension(): void
    {
        [$employer, $company] = $this->employer();
        $expired = $this->job($company, now()->subMinute());
        $available = $this->job($company, now()->addDay(), 'Available Role');

        $this->getJson("/api/v1/jobs/{$expired->id}")
            ->assertOk()->assertJsonPath('data.can_apply', false)
            ->assertJsonPath('data.is_application_deadline_passed', true);
        $this->getJson('/api/v1/jobs?accepting_applications=true')
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $available->id);

        $ownerToken = $this->tokenFor($employer);
        $this->withToken($ownerToken)->getJson('/api/v1/jobs/my')
            ->assertOk()->assertJsonFragment(['id' => $expired->id]);
        $this->withToken($ownerToken)->putJson("/api/v1/jobs/{$expired->id}", [
            'application_deadline' => now()->addDays(2)->toISOString(),
        ])->assertOk()->assertJsonPath('data.can_apply', true);
        $this->assertSame('open', $expired->refresh()->status);
    }

    public function test_publish_rejects_expired_deadline_without_status_or_audit_side_effect(): void
    {
        [$employer, $company] = $this->employer();
        $job = $this->job($company, now()->subMinute(), 'Expired Draft');
        $job->forceFill(['status' => 'draft', 'published_at' => null])->save();
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $job->skills()->attach($skill->id);

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'JOB_APPLICATION_DEADLINE_PASSED');

        $this->assertSame('draft', $job->refresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'job.published', 'entity_id' => $job->id]);
    }

    private function payload(): array
    {
        return [
            'title' => 'Deadline Backend Developer',
            'description' => 'Build recruitment APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'work_mode' => 'remote',
        ];
    }

    private function employer(): array
    {
        $company = Company::create(['name' => 'Deadline Co.', 'approval_status' => 'approved']);
        $user = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return [$user, $company];
    }

    private function candidate(): User
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $user->id]);

        return $user;
    }

    private function job(Company $company, Carbon $deadline, string $title = 'Boundary Role'): JobPosting
    {
        return JobPosting::create([
            ...$this->payload(),
            'company_id' => $company->id,
            'title' => $title,
            'status' => 'open',
            'published_at' => now()->subDay(),
            'application_deadline' => $deadline,
        ]);
    }

    private function applicationPayload(User $candidate): array
    {
        $cv = CVFile::create([
            'user_id' => $candidate->id,
            'original_name' => Str::uuid().'.pdf',
            'stored_path' => 'cv-files/'.Str::uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1000,
            'status' => 'parsed',
        ]);

        return ['selected_cv_file_id' => $cv->id, 'consent_to_share_profile' => true];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
