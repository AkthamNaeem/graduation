<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\SampleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_can_create_view_update_and_delete_own_job_posting(): void
    {
        $user = $this->employer();

        $createResponse = $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/jobs', [
                'title' => 'Backend Engineer',
                'description' => 'Build Laravel APIs for candidate and employer workflows.',
                'requirements' => 'Laravel, MySQL, and REST API experience.',
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
                'location' => 'Remote',
                'work_mode' => 'remote',
                'salary_min' => 70000,
                'salary_max' => 90000,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.company_id', $user->employerProfile->company_id);

        $jobId = $createResponse->json('data.id');

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/my')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $jobId)
            ->assertJsonPath('data.meta.current_page', 1);

        $this->withToken($this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Backend Engineer');

        $this->withToken($this->tokenFor($user))
            ->putJson("/api/v1/jobs/{$jobId}", [
                'title' => 'Senior Backend Engineer',
                'salary_max' => 95000,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Senior Backend Engineer')
            ->assertJsonPath('data.salary_max', '95000.00');

        $this->withToken($this->tokenFor($user))
            ->deleteJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('job_postings', [
            'id' => $jobId,
        ]);
    }

    public function test_employer_can_attach_and_detach_skills_idempotently(): void
    {
        $user = $this->employer();
        $jobPosting = $this->jobPostingFor($user->employerProfile->company);
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $mysql = Skill::create(['name' => 'MySQL', 'slug' => 'mysql']);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/skills", [
                'skill_ids' => [$laravel->id, $mysql->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.skills');

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/skills", [
                'skill_ids' => [$laravel->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.skills');

        $this->assertDatabaseCount('job_posting_skills', 2);

        $this->withToken($this->tokenFor($user))
            ->deleteJson("/api/v1/jobs/{$jobPosting->id}/skills/{$laravel->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.skills')
            ->assertJsonPath('data.skills.0.name', 'MySQL');

        $this->assertDatabaseCount('job_posting_skills', 1);
    }

    public function test_job_must_have_a_skill_before_it_can_be_published_and_can_be_closed_and_reopened(): void
    {
        $user = $this->employer();
        $jobPosting = $this->jobPostingFor($user->employerProfile->company);
        $skill = Skill::create(['name' => 'REST APIs', 'slug' => 'rest-apis']);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['skills'],
            ]);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/skills", [
                'skill_ids' => [$skill->id],
            ])
            ->assertOk();

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->assertNotNull($jobPosting->refresh()->published_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'job.published',
            'entity_type' => JobPosting::class,
            'entity_id' => $jobPosting->id,
            'actor_user_id' => $user->id,
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_pending_company_employer_cannot_publish_job(): void
    {
        $company = Company::create(['name' => 'Pending Co.', 'approval_status' => 'pending']);
        $user = $this->employer('pending@example.com', $company);
        $jobPosting = $this->jobPostingFor($company);
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $jobPosting->skills()->attach($skill);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.company_approval_status.0', 'pending');

        $this->assertSame('draft', $jobPosting->refresh()->status);
    }

    public function test_rejected_company_employer_cannot_access_sensitive_employer_workflows(): void
    {
        $company = Company::create(['name' => 'Rejected Co.', 'approval_status' => 'rejected']);
        $user = $this->employer('rejected@example.com', $company);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/jobs', [
                'title' => 'Blocked Role',
                'description' => 'Should not be created.',
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
            ])
            ->assertForbidden()
            ->assertJsonPath('errors.company_approval_status.0', 'rejected');
    }

    public function test_suspended_company_employer_cannot_access_sensitive_employer_workflows(): void
    {
        $company = Company::create(['name' => 'Suspended Co.', 'approval_status' => 'suspended']);
        $user = $this->employer('suspended-company@example.com', $company);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/jobs', [
                'title' => 'Blocked Role',
                'description' => 'Should not be created.',
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
            ])
            ->assertForbidden()
            ->assertJsonPath('errors.company_approval_status.0', 'suspended');
    }

    public function test_approved_company_employer_can_publish_job(): void
    {
        $company = Company::create(['name' => 'Approved Co.', 'approval_status' => 'approved']);
        $user = $this->employer('approved@example.com', $company);
        $jobPosting = $this->jobPostingFor($company);
        $skill = Skill::create(['name' => 'APIs', 'slug' => 'apis']);
        $jobPosting->skills()->attach($skill);

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_public_visibility_and_mutation_authorization_are_enforced(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $owner = $this->employer('owner@example.com', $company);
        $otherEmployer = $this->employer('other@example.com');
        $jobSeeker = $this->jobSeeker();

        $openJob = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subDay(),
            'title' => 'Open Role',
        ]);
        $draftJob = $this->jobPostingFor($company, [
            'title' => 'Draft Role',
        ]);

        $this->getJson("/api/v1/jobs/{$openJob->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Open Role');

        $this->getJson("/api/v1/jobs/{$draftJob->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($owner))
            ->getJson("/api/v1/jobs/{$draftJob->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Draft Role');

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson("/api/v1/jobs/{$draftJob->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($otherEmployer))
            ->putJson("/api/v1/jobs/{$draftJob->id}", [
                'title' => 'Hijacked',
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($jobSeeker))
            ->postJson('/api/v1/jobs', [
                'title' => 'Should Not Work',
                'description' => 'No employer profile.',
                'employment_type' => 'full-time',
                'experience_level' => 'junior',
            ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_public_and_employer_job_filters_work_as_expected(): void
    {
        $company = Company::create(['name' => 'Filter Co.', 'approval_status' => 'approved']);
        $employer = $this->employer('filters@example.com', $company);
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $react = Skill::create(['name' => 'React', 'slug' => 'react']);

        $laravelJob = $this->jobPostingFor($company, [
            'title' => 'Laravel Platform Engineer',
            'description' => 'Build backend recruitment workflows.',
            'experience_level' => 'senior',
            'location' => 'Remote',
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        $laravelJob->skills()->attach($laravel);

        $reactJob = $this->jobPostingFor($company, [
            'title' => 'React Frontend Engineer',
            'description' => 'Build employer dashboards.',
            'experience_level' => 'mid-level',
            'location' => 'Berlin',
            'status' => 'open',
            'published_at' => now()->subHours(12),
        ]);
        $reactJob->skills()->attach($react);

        $draftLaravelJob = $this->jobPostingFor($company, [
            'title' => 'Hidden Laravel Draft',
            'description' => 'Hidden from public listings.',
            'experience_level' => 'senior',
            'location' => 'Remote',
            'status' => 'draft',
            'published_at' => null,
        ]);
        $draftLaravelJob->skills()->attach($laravel);

        $this->getJson('/api/v1/jobs?search=Laravel')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Laravel Platform Engineer')
            ->assertJsonPath('data.meta.current_page', 1);

        $this->getJson('/api/v1/jobs?location=Berlin')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'React Frontend Engineer');

        $this->getJson('/api/v1/jobs?experience_level=senior')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Laravel Platform Engineer');

        $this->getJson('/api/v1/jobs?skill=laravel')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Laravel Platform Engineer');

        $this->getJson('/api/v1/jobs?skill='.$react->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'React Frontend Engineer');

        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/jobs/my?search=Laravel')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_public_job_listing_filters_by_salary_and_employment_type(): void
    {
        $company = Company::create(['name' => 'Salary Filter Co.', 'approval_status' => 'approved']);

        $juniorJob = $this->jobPostingFor($company, [
            'title' => 'Junior Backend Engineer',
            'employment_type' => 'full-time',
            'salary_min' => 500,
            'salary_max' => 900,
            'status' => 'open',
            'published_at' => now()->subDays(3),
        ]);
        $this->jobPostingFor($company, [
            'title' => 'Senior Backend Engineer',
            'employment_type' => 'contract',
            'salary_min' => 1500,
            'salary_max' => 2500,
            'status' => 'open',
            'published_at' => now()->subDays(2),
        ]);
        $openEndedJob = $this->jobPostingFor($company, [
            'title' => 'Flexible Salary Engineer',
            'employment_type' => 'full-time',
            'salary_min' => null,
            'salary_max' => null,
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/jobs?employment_type=contract')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Senior Backend Engineer');

        $this->getJson('/api/v1/jobs?salary_min=1000')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonMissing(['title' => $juniorJob->title]);

        $this->getJson('/api/v1/jobs?salary_max=1000')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonMissing(['title' => 'Senior Backend Engineer']);

        $this->getJson('/api/v1/jobs?salary_min=800&salary_max=1600')
            ->assertOk()
            ->assertJsonCount(3, 'data.data')
            ->assertJsonFragment(['id' => $juniorJob->id])
            ->assertJsonFragment(['id' => $openEndedJob->id]);
    }

    public function test_public_job_listing_sorts_by_salary_min_ascending(): void
    {
        $company = Company::create(['name' => 'Sort Co.', 'approval_status' => 'approved']);

        $this->jobPostingFor($company, [
            'title' => 'Higher Salary Job',
            'salary_min' => 2000,
            'salary_max' => 3000,
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        $this->jobPostingFor($company, [
            'title' => 'Lower Salary Job',
            'salary_min' => 1000,
            'salary_max' => 1500,
            'status' => 'open',
            'published_at' => now()->subDays(2),
        ]);

        $this->getJson('/api/v1/jobs?sort_by=salary_min&sort_direction=asc')
            ->assertOk()
            ->assertJsonPath('data.data.0.title', 'Lower Salary Job')
            ->assertJsonPath('data.data.1.title', 'Higher Salary Job');
    }

    public function test_public_job_listing_rejects_invalid_salary_range_and_sort_field(): void
    {
        $this->getJson('/api/v1/jobs?salary_min=2000&salary_max=1000')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['salary_max']);

        $this->getJson('/api/v1/jobs?sort_by=status')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['sort_by']);
    }

    public function test_public_job_listing_only_returns_open_jobs(): void
    {
        $company = Company::create(['name' => 'Visibility Co.', 'approval_status' => 'approved']);

        $openJob = $this->jobPostingFor($company, [
            'title' => 'Visible Open Job',
            'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        $this->jobPostingFor($company, [
            'title' => 'Hidden Draft Job',
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->jobPostingFor($company, [
            'title' => 'Hidden Closed Job',
            'status' => 'closed',
            'published_at' => now()->subDays(2),
        ]);

        $this->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $openJob->id)
            ->assertJsonMissing(['title' => 'Hidden Draft Job'])
            ->assertJsonMissing(['title' => 'Hidden Closed Job']);
    }

    public function test_sample_user_seeder_creates_job_postings_with_skills(): void
    {
        $this->seed(SampleUserSeeder::class);

        $this->assertDatabaseCount('job_postings', 3);
        $this->assertDatabaseCount('job_posting_skills', 12);
        $this->assertDatabaseHas('job_postings', [
            'title' => 'Senior Laravel Backend Engineer',
            'status' => 'open',
            'education_level' => 'bachelor',
        ]);
        $jobId = JobPosting::query()->where('title', 'Senior Laravel Backend Engineer')->value('id');
        $laravelId = Skill::query()->where('slug', 'laravel')->value('id');
        $dockerId = Skill::query()->where('slug', 'docker')->value('id');
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $jobId,
            'skill_id' => $laravelId,
            'requirement_type' => 'required',
            'weight' => 5,
        ]);
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $jobId,
            'skill_id' => $dockerId,
            'requirement_type' => 'nice_to_have',
            'weight' => 2,
        ]);
    }

    private function employer(string $email = 'employer@example.com', ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Acme Hiring Co. '.$email, 'approval_status' => 'approved']);

        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::EMPLOYER,
        ]);

        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return $user->load('employerProfile.company');
    }

    private function jobSeeker(string $email = 'jobseeker@example.com'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::JOB_SEEKER,
        ]);

        JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => 'Backend Developer',
        ]);

        return $user->load('jobSeekerProfile');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function jobPostingFor(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Platform Engineer',
            'description' => 'Build smart recruitment APIs.',
            'requirements' => 'Laravel and REST API experience.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'salary_min' => 70000,
            'salary_max' => 90000,
            'status' => 'draft',
            'published_at' => null,
        ], $overrides));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
