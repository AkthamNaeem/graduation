<?php

namespace Tests\Feature\Api\V1;

use App\Enums\JobWorkMode;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobWorkModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_job_can_be_created_without_location_and_resource_exposes_work_mode(): void
    {
        $response = $this->withToken($this->tokenFor($this->employer()))
            ->postJson('/api/v1/jobs', $this->payload(JobWorkMode::REMOTE->value))
            ->assertCreated()
            ->assertJsonPath('data.work_mode', JobWorkMode::REMOTE->value)
            ->assertJsonPath('data.location', null);

        $this->assertDatabaseHas('job_postings', [
            'id' => $response->json('data.id'),
            'work_mode' => JobWorkMode::REMOTE->value,
            'location' => null,
        ]);
    }

    public function test_on_site_job_can_be_created_with_location(): void
    {
        $payload = $this->payload(JobWorkMode::ON_SITE->value);
        $payload['location'] = 'Damascus';

        $this->withToken($this->tokenFor($this->employer()))
            ->postJson('/api/v1/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.work_mode', JobWorkMode::ON_SITE->value)
            ->assertJsonPath('data.location', 'Damascus');
    }

    public function test_hybrid_job_can_be_created_with_location(): void
    {
        $payload = $this->payload(JobWorkMode::HYBRID->value);
        $payload['location'] = 'Damascus';

        $this->withToken($this->tokenFor($this->employer()))
            ->postJson('/api/v1/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.work_mode', JobWorkMode::HYBRID->value)
            ->assertJsonPath('data.location', 'Damascus');
    }

    public function test_on_site_job_creation_requires_location(): void
    {
        $this->withToken($this->tokenFor($this->employer()))
            ->postJson('/api/v1/jobs', $this->payload(JobWorkMode::ON_SITE->value))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location']);
    }

    public function test_hybrid_job_creation_requires_location(): void
    {
        $this->withToken($this->tokenFor($this->employer()))
            ->postJson('/api/v1/jobs', $this->payload(JobWorkMode::HYBRID->value))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location']);
    }

    public function test_job_creation_rejects_an_unknown_work_mode(): void
    {
        $this->withToken($this->tokenFor($this->employer()))
            ->postJson('/api/v1/jobs', $this->payload('virtual'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_mode']);
    }

    public function test_job_creation_requires_work_mode(): void
    {
        $payload = $this->payload(JobWorkMode::REMOTE->value);
        unset($payload['work_mode']);

        $this->withToken($this->tokenFor($this->employer()))
            ->postJson('/api/v1/jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_mode']);
    }

    public function test_owner_can_change_on_site_job_to_remote_without_clearing_location(): void
    {
        $employer = $this->employer();
        $jobPosting = $this->jobPostingFor($employer->employerProfile->company, [
            'work_mode' => JobWorkMode::ON_SITE->value,
            'location' => 'Damascus',
        ]);

        $this->withToken($this->tokenFor($employer))
            ->putJson("/api/v1/jobs/{$jobPosting->id}", ['work_mode' => JobWorkMode::REMOTE->value])
            ->assertOk()
            ->assertJsonPath('data.work_mode', JobWorkMode::REMOTE->value)
            ->assertJsonPath('data.location', 'Damascus');
    }

    public function test_remote_job_cannot_change_to_hybrid_without_an_effective_location(): void
    {
        $employer = $this->employer();
        $jobPosting = $this->jobPostingFor($employer->employerProfile->company);

        $this->withToken($this->tokenFor($employer))
            ->putJson("/api/v1/jobs/{$jobPosting->id}", ['work_mode' => JobWorkMode::HYBRID->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location']);

        $this->assertSame(JobWorkMode::REMOTE, $jobPosting->refresh()->work_mode);
    }

    public function test_remote_job_can_change_to_hybrid_with_location(): void
    {
        $employer = $this->employer();
        $jobPosting = $this->jobPostingFor($employer->employerProfile->company);

        $this->withToken($this->tokenFor($employer))
            ->putJson("/api/v1/jobs/{$jobPosting->id}", [
                'work_mode' => JobWorkMode::HYBRID->value,
                'location' => 'Damascus',
            ])
            ->assertOk()
            ->assertJsonPath('data.work_mode', JobWorkMode::HYBRID->value)
            ->assertJsonPath('data.location', 'Damascus');
    }

    public function test_employer_cannot_update_another_company_job_work_mode(): void
    {
        $owner = $this->employer('owner@example.com');
        $otherEmployer = $this->employer('other@example.com');
        $jobPosting = $this->jobPostingFor($owner->employerProfile->company);

        $this->withToken($this->tokenFor($otherEmployer))
            ->putJson("/api/v1/jobs/{$jobPosting->id}", ['work_mode' => JobWorkMode::REMOTE->value])
            ->assertForbidden();
    }

    public function test_public_remote_filter_returns_only_remote_jobs(): void
    {
        $company = $this->approvedCompany();
        $remote = $this->openJob($company, JobWorkMode::REMOTE, 'Remote Backend');
        $this->openJob($company, JobWorkMode::ON_SITE, 'On-Site Backend');

        $this->getJson('/api/v1/jobs?work_mode=remote')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $remote->id)
            ->assertJsonPath('data.data.0.work_mode', JobWorkMode::REMOTE->value);
    }

    public function test_public_on_site_filter_returns_only_on_site_jobs(): void
    {
        $company = $this->approvedCompany();
        $this->openJob($company, JobWorkMode::REMOTE, 'Remote Backend');
        $onSite = $this->openJob($company, JobWorkMode::ON_SITE, 'On-Site Backend');

        $this->getJson('/api/v1/jobs?work_mode=on_site')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $onSite->id)
            ->assertJsonPath('data.data.0.work_mode', JobWorkMode::ON_SITE->value);
    }

    public function test_public_work_mode_filter_rejects_an_unknown_value(): void
    {
        $this->getJson('/api/v1/jobs?work_mode=virtual')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_mode']);
    }

    public function test_public_work_mode_filter_combines_with_search_employment_type_and_sorting(): void
    {
        $company = $this->approvedCompany();
        $matching = $this->openJob($company, JobWorkMode::REMOTE, 'Backend Developer', [
            'employment_type' => 'full-time',
            'published_at' => now()->subHour(),
        ]);
        $this->openJob($company, JobWorkMode::REMOTE, 'Frontend Developer', ['employment_type' => 'full-time']);
        $this->openJob($company, JobWorkMode::ON_SITE, 'Backend Architect', ['employment_type' => 'full-time']);
        $this->openJob($company, JobWorkMode::REMOTE, 'Backend Contractor', ['employment_type' => 'contract']);

        $this->getJson('/api/v1/jobs?search=backend&work_mode=remote&employment_type=full-time&sort_by=published_at&sort_direction=desc')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $matching->id);
    }

    public function test_public_work_mode_filter_never_exposes_draft_or_closed_jobs(): void
    {
        $company = $this->approvedCompany();
        $open = $this->openJob($company, JobWorkMode::REMOTE, 'Open Remote Job');
        $this->jobPostingFor($company, ['title' => 'Draft Remote Job']);
        $this->jobPostingFor($company, [
            'title' => 'Closed Remote Job',
            'status' => 'closed',
            'published_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/jobs?work_mode=remote')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $open->id)
            ->assertJsonMissing(['title' => 'Draft Remote Job'])
            ->assertJsonMissing(['title' => 'Closed Remote Job']);
    }

    public function test_employer_job_listing_filters_work_mode_within_own_company(): void
    {
        $employer = $this->employer();
        $remote = $this->jobPostingFor($employer->employerProfile->company, ['title' => 'Own Remote Job']);
        $this->jobPostingFor($employer->employerProfile->company, [
            'title' => 'Own Hybrid Job',
            'work_mode' => JobWorkMode::HYBRID->value,
            'location' => 'Damascus',
        ]);
        $otherEmployer = $this->employer('other@example.com');
        $this->jobPostingFor($otherEmployer->employerProfile->company, ['title' => 'Other Remote Job']);

        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/jobs/my?work_mode=remote')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $remote->id);
    }

    public function test_on_site_and_hybrid_jobs_without_location_cannot_be_published(): void
    {
        $employer = $this->employer();

        foreach ([JobWorkMode::ON_SITE, JobWorkMode::HYBRID] as $workMode) {
            $jobPosting = $this->jobPostingFor($employer->employerProfile->company, [
                'title' => $workMode->value.' Job',
                'work_mode' => $workMode->value,
                'location' => null,
            ]);
            $this->attachRequiredSkill($jobPosting);

            $this->withToken($this->tokenFor($employer))
                ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['work_mode']);

            $this->assertSame('draft', $jobPosting->refresh()->status);
        }
    }

    public function test_remote_job_without_location_can_be_published_when_other_requirements_are_valid(): void
    {
        $employer = $this->employer();
        $jobPosting = $this->jobPostingFor($employer->employerProfile->company);
        $this->attachRequiredSkill($jobPosting);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.work_mode', JobWorkMode::REMOTE->value)
            ->assertJsonPath('data.location', null);
    }

    public function test_legacy_job_with_unknown_work_mode_cannot_be_published(): void
    {
        $employer = $this->employer();
        $jobId = DB::table('job_postings')->insertGetId([
            'company_id' => $employer->employerProfile->company_id,
            'title' => 'Legacy Invalid Job',
            'description' => 'Legacy invalid data.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => null,
            'work_mode' => 'virtual',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $jobPosting = JobPosting::query()->findOrFail($jobId);
        $this->attachRequiredSkill($jobPosting);

        $this->withToken($this->tokenFor($employer))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_mode']);

        $this->assertDatabaseHas('job_postings', ['id' => $jobId, 'status' => 'draft']);
    }

    /** @return array<string, string> */
    private function payload(string $mode): array
    {
        return [
            'title' => ucfirst($mode).' Backend Developer',
            'description' => 'Build recruitment APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'work_mode' => $mode,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function jobPostingFor(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::query()->create(array_merge([
            'company_id' => $company->id,
            'title' => 'Remote Platform Engineer',
            'description' => 'Build smart recruitment APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => null,
            'work_mode' => JobWorkMode::REMOTE->value,
            'status' => 'draft',
            'published_at' => null,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function openJob(Company $company, JobWorkMode $workMode, string $title, array $overrides = []): JobPosting
    {
        return $this->jobPostingFor($company, array_merge([
            'title' => $title,
            'work_mode' => $workMode->value,
            'location' => $workMode->requiresLocation() ? 'Damascus' : null,
            'status' => 'open',
            'published_at' => now()->subDay(),
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

    private function approvedCompany(?string $name = null): Company
    {
        return Company::query()->create([
            'name' => $name ?? 'Work Mode Co. '.Str::random(8),
            'approval_status' => 'approved',
        ]);
    }

    private function employer(string $email = 'employer@example.com'): User
    {
        $company = $this->approvedCompany();
        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::EMPLOYER,
        ]);
        EmployerProfile::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return $user->load('employerProfile.company');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
