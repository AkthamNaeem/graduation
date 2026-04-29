<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Education;
use App\Models\EmployerProfile;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_seeker_can_get_and_update_profile_with_nested_data(): void
    {
        $user = $this->jobSeeker();
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $user->jobSeekerProfile->skills()->attach($skill);
        Experience::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Acme',
        ]);
        Education::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'institution' => 'State University',
        ]);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.experiences.0.title', 'Backend Developer')
            ->assertJsonPath('data.education.0.institution', 'State University')
            ->assertJsonPath('data.skills.0.name', 'Laravel');

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/profile', [
                'headline' => 'Laravel API Developer',
                'summary' => 'Builds recruitment APIs.',
                'phone' => '+1 555 0101',
                'location' => 'Remote',
                'portfolio_url' => 'https://portfolio.example.com',
                'linkedin_url' => 'https://www.linkedin.com/in/example',
                'github_url' => 'https://github.com/example',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.headline', 'Laravel API Developer')
            ->assertJsonPath('data.github_url', 'https://github.com/example');

        $this->assertDatabaseHas('job_seeker_profiles', [
            'user_id' => $user->id,
            'headline' => 'Laravel API Developer',
        ]);
    }

    public function test_profile_update_validates_urls(): void
    {
        $user = $this->jobSeeker();

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/profile', [
                'portfolio_url' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['portfolio_url'],
            ]);
    }

    public function test_employer_cannot_access_job_seeker_profile_endpoints(): void
    {
        $user = $this->employer();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_job_seeker_can_manage_experiences(): void
    {
        $user = $this->jobSeeker();

        $createResponse = $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/profile/experiences', [
                'title' => 'Backend Developer',
                'company_name' => 'Northwind',
                'location' => 'Remote',
                'start_date' => '2023-01-01',
                'is_current' => true,
                'description' => 'Built Laravel services.',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Backend Developer');

        $experienceId = $createResponse->json('data.id');

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile/experiences')
            ->assertOk()
            ->assertJsonPath('data.0.id', $experienceId);

        $this->withToken($this->tokenFor($user))
            ->getJson("/api/v1/profile/experiences/{$experienceId}")
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Northwind');

        $this->withToken($this->tokenFor($user))
            ->putJson("/api/v1/profile/experiences/{$experienceId}", [
                'title' => 'Senior Backend Developer',
                'description' => 'Built and maintained Laravel services.',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Senior Backend Developer');

        $this->withToken($this->tokenFor($user))
            ->deleteJson("/api/v1/profile/experiences/{$experienceId}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('experiences', [
            'id' => $experienceId,
        ]);
    }

    public function test_experience_validation_and_ownership_are_enforced(): void
    {
        $user = $this->jobSeeker('owner@example.com');
        $otherUser = $this->jobSeeker('other@example.com');
        $otherExperience = Experience::create([
            'job_seeker_profile_id' => $otherUser->jobSeekerProfile->id,
            'title' => 'Private Role',
            'company_name' => 'Private Co',
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/profile/experiences', [
                'company_name' => 'Missing Title Co',
            ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['title'],
            ]);

        $this->withToken($this->tokenFor($user))
            ->getJson("/api/v1/profile/experiences/{$otherExperience->id}")
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_job_seeker_can_manage_education_records(): void
    {
        $user = $this->jobSeeker();

        $createResponse = $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/profile/education', [
                'institution' => 'State University',
                'degree' => 'Bachelor of Science',
                'field_of_study' => 'Computer Science',
                'start_date' => '2018-09-01',
                'end_date' => '2022-06-01',
                'description' => 'Software engineering.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.institution', 'State University');

        $educationId = $createResponse->json('data.id');

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile/education')
            ->assertOk()
            ->assertJsonPath('data.0.id', $educationId);

        $this->withToken($this->tokenFor($user))
            ->putJson("/api/v1/profile/education/{$educationId}", [
                'degree' => 'B.Sc.',
            ])
            ->assertOk()
            ->assertJsonPath('data.degree', 'B.Sc.');

        $this->withToken($this->tokenFor($user))
            ->deleteJson("/api/v1/profile/education/{$educationId}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('education', [
            'id' => $educationId,
        ]);
    }

    public function test_skill_attach_detach_and_duplicate_attach_are_idempotent(): void
    {
        $user = $this->jobSeeker();
        $skill = Skill::create(['name' => 'MySQL', 'slug' => 'mysql']);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/profile/skills', [
                'skill_id' => $skill->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.skills.0.name', 'MySQL');

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/v1/profile/skills', [
                'skill_id' => $skill->id,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.skills');

        $this->assertDatabaseCount('job_seeker_skills', 1);

        $this->withToken($this->tokenFor($user))
            ->deleteJson("/api/v1/profile/skills/{$skill->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.skills');

        $this->assertDatabaseCount('job_seeker_skills', 0);
    }

    public function test_employer_can_get_and_update_company_and_profile(): void
    {
        $user = $this->employer();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Acme Hiring Co.');

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/company', [
                'name' => 'Acme Talent',
                'industry' => 'Recruitment Technology',
                'website' => 'https://acme.example.com',
                'location' => 'New York, NY',
                'description' => 'Recruitment platform employer.',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Talent')
            ->assertJsonPath('data.website', 'https://acme.example.com');

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/employer/profile')
            ->assertOk()
            ->assertJsonPath('data.company.name', 'Acme Talent');

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/employer/profile', [
                'job_title' => 'Talent Acquisition Lead',
                'phone' => '+1 555 0199',
                'bio' => 'Hiring backend engineers.',
            ])
            ->assertOk()
            ->assertJsonPath('data.job_title', 'Talent Acquisition Lead');

        $this->assertDatabaseHas('companies', [
            'name' => 'Acme Talent',
        ]);
        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $user->id,
            'job_title' => 'Talent Acquisition Lead',
        ]);
    }

    public function test_employer_company_update_validates_website(): void
    {
        $user = $this->employer();

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/company', [
                'website' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['website'],
            ]);
    }

    public function test_job_seeker_cannot_access_employer_endpoints(): void
    {
        $user = $this->jobSeeker();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/company')
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/employer/profile')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
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

    private function employer(string $email = 'employer@example.com'): User
    {
        $company = Company::create([
            'name' => 'Acme Hiring Co.',
        ]);
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

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
