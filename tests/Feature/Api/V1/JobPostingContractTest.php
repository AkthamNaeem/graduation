<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobPostingContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-01T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_create_persists_and_returns_all_contract_fields(): void
    {
        [$employer] = $this->employer();
        $payload = [
            ...$this->payload(),
            'department' => 'Engineering',
            'responsibilities' => 'Build APIs, review code, and maintain tests.',
            'benefits' => 'Remote work and flexible hours.',
            'application_deadline' => '2026-08-31T20:00:00Z',
        ];

        $response = $this->withToken($this->tokenFor($employer))->postJson('/api/v1/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.department', $payload['department'])
            ->assertJsonPath('data.responsibilities', $payload['responsibilities'])
            ->assertJsonPath('data.requirements', $payload['requirements'])
            ->assertJsonPath('data.benefits', $payload['benefits'])
            ->assertJsonPath('data.application_deadline', '2026-08-31T20:00:00.000000Z')
            ->assertJsonPath('data.is_accepting_applications', false);

        $this->assertDatabaseHas('job_postings', [
            'id' => $response->json('data.id'),
            'department' => $payload['department'],
            'responsibilities' => $payload['responsibilities'],
            'requirements' => $payload['requirements'],
            'benefits' => $payload['benefits'],
        ]);
    }

    public function test_create_accepts_nullable_optional_contract_fields_and_no_deadline(): void
    {
        [$employer] = $this->employer();

        $this->withToken($this->tokenFor($employer))->postJson('/api/v1/jobs', [
            ...$this->payload(),
            'department' => null,
            'responsibilities' => null,
            'benefits' => null,
            'application_deadline' => null,
        ])->assertCreated()
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.responsibilities', null)
            ->assertJsonPath('data.benefits', null)
            ->assertJsonPath('data.application_deadline', null);
    }

    public function test_create_rejects_missing_and_blank_requirements(): void
    {
        [$employer] = $this->employer();
        $token = $this->tokenFor($employer);
        $missing = $this->payload();
        unset($missing['requirements']);

        $this->withToken($token)->postJson('/api/v1/jobs', $missing)
            ->assertUnprocessable()->assertJsonValidationErrors(['requirements']);
        $this->withToken($token)->postJson('/api/v1/jobs', [...$this->payload(), 'requirements' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['requirements']);
    }

    public function test_create_rejects_invalid_and_expired_deadlines(): void
    {
        [$employer] = $this->employer();
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson('/api/v1/jobs', [...$this->payload(), 'application_deadline' => 'not-a-date'])
            ->assertUnprocessable()->assertJsonValidationErrors(['application_deadline']);
        $this->withToken($token)->postJson('/api/v1/jobs', [...$this->payload(), 'application_deadline' => now()->subSecond()->toISOString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['application_deadline']);
    }

    public function test_create_validates_contract_text_limits(): void
    {
        [$employer] = $this->employer();

        $this->withToken($this->tokenFor($employer))->postJson('/api/v1/jobs', [
            ...$this->payload(),
            'department' => Str::repeat('D', 256),
            'responsibilities' => Str::repeat('R', 20001),
            'requirements' => Str::repeat('Q', 20001),
            'benefits' => Str::repeat('B', 20001),
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'department',
            'responsibilities',
            'requirements',
            'benefits',
        ]);
    }

    public function test_owner_can_update_contract_fields_and_remove_deadline(): void
    {
        [$employer, $company] = $this->employer();
        $jobPosting = $this->job($company, ['application_deadline' => now()->addDay()]);

        $this->withToken($this->tokenFor($employer))->putJson("/api/v1/jobs/{$jobPosting->id}", [
            'department' => 'Platform Engineering',
            'responsibilities' => 'Own the API platform.',
            'requirements' => 'Senior Laravel experience.',
            'benefits' => 'Learning budget.',
            'application_deadline' => null,
        ])->assertOk()
            ->assertJsonPath('data.department', 'Platform Engineering')
            ->assertJsonPath('data.responsibilities', 'Own the API platform.')
            ->assertJsonPath('data.requirements', 'Senior Laravel experience.')
            ->assertJsonPath('data.benefits', 'Learning budget.')
            ->assertJsonPath('data.application_deadline', null);
    }

    public function test_requirements_cannot_be_cleared_on_update(): void
    {
        [$employer, $company] = $this->employer();
        $jobPosting = $this->job($company);
        $token = $this->tokenFor($employer);

        $this->withToken($token)->putJson("/api/v1/jobs/{$jobPosting->id}", ['requirements' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['requirements']);
        $this->withToken($token)->putJson("/api/v1/jobs/{$jobPosting->id}", ['requirements' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['requirements']);
    }

    public function test_expired_job_can_be_edited_and_its_deadline_extended(): void
    {
        [$employer, $company] = $this->employer();
        $jobPosting = $this->job($company, [
            'status' => 'open',
            'published_at' => now()->subDay(),
            'application_deadline' => now()->subSecond(),
        ]);

        $this->withToken($this->tokenFor($employer))->putJson("/api/v1/jobs/{$jobPosting->id}", [
            'department' => 'Engineering',
            'application_deadline' => now()->addDays(2)->toISOString(),
        ])->assertOk()
            ->assertJsonPath('data.department', 'Engineering')
            ->assertJsonPath('data.is_accepting_applications', true);

        $this->assertSame('open', $jobPosting->refresh()->status);
    }

    public function test_other_employer_and_candidate_cannot_update_contract_fields(): void
    {
        [$owner, $company] = $this->employer();
        [$otherEmployer] = $this->employer('other@example.com');
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $jobPosting = $this->job($company);

        $this->withToken($this->tokenFor($otherEmployer))
            ->putJson("/api/v1/jobs/{$jobPosting->id}", ['department' => 'Hijacked'])
            ->assertForbidden();
        $this->withToken($this->tokenFor($candidate))
            ->putJson("/api/v1/jobs/{$jobPosting->id}", ['department' => 'Hijacked'])
            ->assertForbidden();
        $this->assertNotSame($owner->id, $otherEmployer->id);
    }

    public function test_publish_rejects_legacy_job_without_requirements_without_backfilling(): void
    {
        [$employer, $company] = $this->employer();
        $jobPosting = $this->job($company, ['requirements' => null]);
        $this->attachRequiredSkill($jobPosting);

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'JOB_REQUIREMENTS_MISSING')
            ->assertJsonValidationErrors(['requirements']);

        $this->assertDatabaseHas('job_postings', [
            'id' => $jobPosting->id,
            'requirements' => null,
            'status' => 'draft',
        ]);
    }

    public function test_publish_allows_valid_requirements_with_future_or_null_deadline(): void
    {
        [$employer, $company] = $this->employer();
        $token = $this->tokenFor($employer);

        foreach ([null, now()->addDay()] as $index => $deadline) {
            $jobPosting = $this->job($company, [
                'title' => 'Publishable Job '.$index,
                'application_deadline' => $deadline,
            ]);
            $this->attachRequiredSkill($jobPosting);

            $this->withToken($token)->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
                ->assertOk()->assertJsonPath('data.status', 'open');
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'Backend Developer',
            'description' => 'Develop and maintain Laravel APIs.',
            'requirements' => 'Experience with Laravel, MySQL, and REST APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'junior',
            'work_mode' => 'remote',
            'location' => null,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function job(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::query()->create(array_merge([
            ...$this->payload(),
            'company_id' => $company->id,
            'status' => 'draft',
            'published_at' => null,
        ], $overrides));
    }

    private function attachRequiredSkill(JobPosting $jobPosting): void
    {
        $skill = Skill::query()->create([
            'name' => 'Required Skill '.$jobPosting->id,
            'slug' => 'required-skill-'.$jobPosting->id,
        ]);
        $jobPosting->skills()->attach($skill);
    }

    /** @return array{User, Company} */
    private function employer(string $email = 'employer@example.com'): array
    {
        $company = Company::query()->create([
            'name' => 'Contract Co. '.Str::random(8),
            'approval_status' => 'approved',
        ]);
        $employer = User::factory()->create([
            'email' => $email,
            'role' => UserRole::EMPLOYER,
        ]);
        EmployerProfile::query()->create([
            'user_id' => $employer->id,
            'company_id' => $company->id,
        ]);

        return [$employer->load('employerProfile.company'), $company];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
