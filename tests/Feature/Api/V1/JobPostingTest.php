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
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
                'location' => 'Remote',
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

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$jobPosting->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_public_visibility_and_mutation_authorization_are_enforced(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.']);
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
        $company = Company::create(['name' => 'Filter Co.']);
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

    public function test_sample_user_seeder_creates_job_postings_with_skills(): void
    {
        $this->seed(SampleUserSeeder::class);

        $this->assertDatabaseCount('job_postings', 3);
        $this->assertDatabaseCount('job_posting_skills', 9);
        $this->assertDatabaseHas('job_postings', [
            'title' => 'Senior Laravel Backend Engineer',
            'status' => 'open',
        ]);
    }

    private function employer(string $email = 'employer@example.com', ?Company $company = null): User
    {
        $company ??= Company::create(['name' => 'Acme Hiring Co. '.$email]);

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
