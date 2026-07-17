<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\Education;
use App\Models\EmployerProfile;
use App\Models\Experience;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_job_seeker_can_get_recommended_jobs_with_explainable_scores(): void
    {
        $company = Company::create(['name' => 'Acme Hiring Co.', 'approval_status' => 'approved']);
        $jobSeeker = $this->jobSeeker('seeker@example.com');
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $mysql = Skill::create(['name' => 'MySQL', 'slug' => 'mysql']);
        $react = Skill::create(['name' => 'React', 'slug' => 'react']);
        $kubernetes = Skill::create(['name' => 'Kubernetes', 'slug' => 'kubernetes']);

        $jobSeeker->jobSeekerProfile->skills()->attach([$laravel->id, $mysql->id]);
        Experience::create([
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Northwind',
            'location' => 'Remote',
            'start_date' => '2023-01-01',
            'end_date' => null,
            'is_current' => true,
            'description' => 'Built Laravel APIs and MySQL services.',
        ]);
        Education::create([
            'job_seeker_profile_id' => $jobSeeker->jobSeekerProfile->id,
            'institution' => 'State University',
            'degree' => 'Bachelor of Science',
            'field_of_study' => 'Computer Science',
            'start_date' => '2018-09-01',
            'end_date' => '2022-06-01',
            'description' => 'Studied databases and software engineering.',
        ]);

        $topMatch = $this->jobPostingFor($company, [
            'title' => 'Senior Laravel Engineer',
            'description' => 'Build Laravel APIs and MySQL services.',
            'experience_level' => 'senior',
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $topMatch->skills()->attach([
            $laravel->id => ['requirement_type' => 'required'],
            $mysql->id => ['requirement_type' => 'optional'],
            $kubernetes->id => ['requirement_type' => 'required'],
        ]);

        $lowerMatch = $this->jobPostingFor($company, [
            'title' => 'Frontend Engineer',
            'description' => 'Build React dashboards.',
            'experience_level' => 'mid-level',
            'status' => 'open',
            'published_at' => now()->subMinutes(30),
        ]);
        $lowerMatch->skills()->attach([$react->id]);

        $alreadyApplied = $this->jobPostingFor($company, [
            'title' => 'Applied Backend Role',
            'description' => 'Laravel APIs and MySQL.',
            'status' => 'open',
            'published_at' => now()->subMinutes(10),
        ]);
        $alreadyApplied->skills()->attach([$laravel->id, $mysql->id]);
        $this->applicationFor($alreadyApplied, $jobSeeker->jobSeekerProfile);

        $closedJob = $this->jobPostingFor($company, [
            'title' => 'Closed Role',
            'description' => 'Laravel APIs and MySQL.',
            'status' => 'closed',
            'published_at' => now()->subDay(),
        ]);
        $closedJob->skills()->attach([$laravel->id]);

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson('/api/v1/jobs/recommended')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $topMatch->id)
            ->assertJsonPath('data.1.id', $lowerMatch->id)
            ->assertJsonPath('data.0.matched_skills.0', 'Laravel')
            ->assertJsonPath('data.0.matched_skills.1', 'MySQL')
            ->assertJsonPath('data.0.skill_breakdown.required_skills_matched', ['Laravel'])
            ->assertJsonPath('data.0.skill_breakdown.required_skills_missing', ['Kubernetes'])
            ->assertJsonPath('data.0.skill_breakdown.optional_skills_matched', ['MySQL'])
            ->assertJsonPath('data.0.breakdown.skills', 0.6)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [[
                    'id',
                    'title',
                    'score',
                    'breakdown' => ['skills', 'experience', 'core', 'education'],
                    'matched_skills',
                    'skill_breakdown' => ['required_skills_matched', 'required_skills_missing', 'optional_skills_matched'],
                ]],
            ]);
    }

    public function test_recommended_jobs_endpoint_enforces_role_and_limit_validation(): void
    {
        $jobSeeker = $this->jobSeeker('limit-seeker@example.com');
        $employer = $this->employer();
        $company = $employer->employerProfile->company;

        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $jobSeeker->jobSeekerProfile->skills()->attach($laravel);

        $firstJob = $this->jobPostingFor($company, [
            'title' => 'Laravel Engineer',
            'description' => 'Laravel API platform work.',
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $firstJob->skills()->attach($laravel);

        $secondJob = $this->jobPostingFor($company, [
            'title' => 'Second Laravel Engineer',
            'description' => 'Laravel services work.',
            'status' => 'open',
            'published_at' => now()->subMinutes(30),
        ]);
        $secondJob->skills()->attach($laravel);

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson('/api/v1/jobs/recommended?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson('/api/v1/jobs/recommended?limit=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/jobs/recommended')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_owner_employer_can_get_ranked_candidates_with_deterministic_tie_ordering(): void
    {
        $company = Company::create(['name' => 'Ranking Co.', 'approval_status' => 'approved']);
        $ownerEmployer = $this->employer('owner@example.com', $company);
        $jobPosting = $this->jobPostingFor($company, [
            'title' => 'Senior Laravel Engineer',
            'description' => 'Laravel APIs and MySQL services.',
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);

        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $mysql = Skill::create(['name' => 'MySQL', 'slug' => 'mysql']);
        $react = Skill::create(['name' => 'React', 'slug' => 'react']);
        $jobPosting->skills()->attach([$laravel->id, $mysql->id]);

        $topCandidate = $this->jobSeeker('top@example.com');
        $topCandidate->jobSeekerProfile->skills()->attach([$laravel->id, $mysql->id]);
        Experience::create([
            'job_seeker_profile_id' => $topCandidate->jobSeekerProfile->id,
            'title' => 'Senior Laravel Engineer',
            'company_name' => 'Northwind',
            'location' => 'Remote',
            'start_date' => '2022-01-01',
            'end_date' => null,
            'is_current' => true,
            'description' => 'Laravel APIs and MySQL services.',
        ]);

        $tieCandidateOne = $this->jobSeeker('tie-one@example.com');
        $tieCandidateOne->jobSeekerProfile->skills()->attach($react);

        $tieCandidateTwo = $this->jobSeeker('tie-two@example.com');
        $tieCandidateTwo->jobSeekerProfile->skills()->attach($react);

        $topApplication = $this->applicationFor($jobPosting, $topCandidate->jobSeekerProfile, 'under_review');
        $tieApplicationOne = $this->applicationFor($jobPosting, $tieCandidateOne->jobSeekerProfile, 'submitted');
        $tieApplicationTwo = $this->applicationFor($jobPosting, $tieCandidateTwo->jobSeekerProfile, 'submitted');

        $otherJob = $this->jobPostingFor($company, [
            'title' => 'Other Job',
            'description' => 'React dashboards.',
            'status' => 'open',
            'published_at' => now()->subMinutes(30),
        ]);
        $otherJob->skills()->attach($react);
        $otherApplication = $this->applicationFor($otherJob, $this->jobSeeker('other@example.com')->jobSeekerProfile, 'submitted');

        $this->withToken($this->tokenFor($ownerEmployer))
            ->getJson("/api/v1/jobs/{$jobPosting->id}/candidates/ranked")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.job_application_id', $topApplication->id)
            ->assertJsonPath('data.1.job_application_id', $tieApplicationOne->id)
            ->assertJsonPath('data.2.job_application_id', $tieApplicationTwo->id)
            ->assertJsonPath('data.0.application_status.slug', 'under_review')
            ->assertJsonPath('data.0.job_seeker_profile.id', $topCandidate->jobSeekerProfile->id)
            ->assertJsonPath('data.0.matched_skills.0', 'Laravel')
            ->assertJsonPath('data.0.matched_skills.1', 'MySQL')
            ->assertJsonMissing(['job_application_id' => $otherApplication->id])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [[
                    'job_application_id',
                    'application_status',
                    'score',
                    'breakdown' => ['skills', 'experience', 'core', 'education'],
                    'matched_skills',
                    'job_seeker_profile',
                ]],
            ]);
    }

    public function test_ranked_candidates_endpoint_enforces_authorization_and_limit_validation(): void
    {
        $company = Company::create(['name' => 'Guard Co.', 'approval_status' => 'approved']);
        $ownerEmployer = $this->employer('owner-guard@example.com', $company);
        $otherEmployer = $this->employer('other-guard@example.com');
        $jobSeeker = $this->jobSeeker('guard-seeker@example.com');
        $jobPosting = $this->jobPostingFor($company, [
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);

        $this->applicationFor($jobPosting, $jobSeeker->jobSeekerProfile);

        $this->withToken($this->tokenFor($ownerEmployer))
            ->getJson("/api/v1/jobs/{$jobPosting->id}/candidates/ranked?limit=0")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        $this->withToken($this->tokenFor($otherEmployer))
            ->getJson("/api/v1/jobs/{$jobPosting->id}/candidates/ranked")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson("/api/v1/jobs/{$jobPosting->id}/candidates/ranked")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
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
            'summary' => 'Builds APIs and backend services.',
            'location' => 'Remote',
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

    private function applicationFor(
        JobPosting $jobPosting,
        JobSeekerProfile $jobSeekerProfile,
        string $statusSlug = 'submitted',
    ): JobApplication {
        $statusId = ApplicationStatus::query()->where('slug', $statusSlug)->value('id');

        $application = JobApplication::create([
            'job_posting_id' => $jobPosting->id,
            'job_seeker_profile_id' => $jobSeekerProfile->id,
            'application_status_id' => $statusId,
        ]);

        $application->statusHistory()->create([
            'from_application_status_id' => null,
            'to_application_status_id' => $statusId,
            'changed_by_user_id' => $jobSeekerProfile->user_id,
            'note' => null,
        ]);

        return $application->load('applicationStatus', 'jobPosting', 'jobSeekerProfile');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
