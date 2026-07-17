<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_and_job_text_builders_collect_expected_fields(): void
    {
        $service = new MatchingService();
        $profile = $this->profileWithUser();
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $mysql = Skill::create(['name' => 'MySQL', 'slug' => 'mysql']);
        $profile->skills()->attach([$laravel->id, $mysql->id]);

        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Northwind',
            'location' => 'Remote',
            'start_date' => '2023-01-01',
            'end_date' => null,
            'is_current' => true,
            'description' => 'Built Laravel APIs and MySQL reporting.',
        ]);

        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'State University',
            'degree' => 'Bachelor of Science',
            'field_of_study' => 'Computer Science',
            'start_date' => '2018-09-01',
            'end_date' => '2022-06-01',
            'description' => 'Focused on databases and web development.',
        ]);

        $job = $this->jobPostingFor($this->company(), [
            'title' => 'Senior Laravel Engineer',
            'description' => 'Lead API delivery with Laravel and MySQL.',
            'employment_type' => 'full-time',
            'experience_level' => 'senior',
            'location' => 'Remote',
        ]);
        $job->skills()->attach([$laravel->id, $mysql->id]);

        $profileText = $service->buildTextFromProfile($profile);
        $jobText = $service->buildTextFromJob($job);

        $this->assertStringContainsString('Laravel Backend Developer', $profileText['core']);
        $this->assertStringContainsString('Laravel MySQL', $profileText['skills']);
        $this->assertStringContainsString('Backend Developer Northwind Remote', $profileText['experience']);
        $this->assertStringContainsString('State University Bachelor of Science Computer Science', $profileText['education']);

        $this->assertStringContainsString('Senior Laravel Engineer', $jobText['core']);
        $this->assertStringContainsString('Laravel MySQL', $jobText['skills']);
        $this->assertStringContainsString('senior Senior Laravel Engineer Lead API delivery with Laravel and MySQL.', $jobText['experience']);
        $this->assertSame('', $jobText['education']);
    }

    public function test_compute_tfidf_is_deterministic_for_known_documents(): void
    {
        $service = new MatchingService();
        $documents = [
            'doc1' => 'laravel laravel mysql',
            'doc2' => 'laravel php',
        ];

        $firstPass = $service->computeTFIDF($documents);
        $secondPass = $service->computeTFIDF($documents);

        $this->assertSame($firstPass, $secondPass);
        $this->assertEqualsWithDelta(0.666667, $firstPass['doc1']['laravel'], 0.000001);
        $this->assertEqualsWithDelta(0.468488, $firstPass['doc1']['mysql'], 0.000001);
        $this->assertEqualsWithDelta(0.702733, $firstPass['doc2']['php'], 0.000001);
    }

    public function test_cosine_similarity_handles_identical_disjoint_and_zero_vectors(): void
    {
        $service = new MatchingService();

        $this->assertEqualsWithDelta(
            1.0,
            $service->cosineSimilarity(['laravel' => 0.5, 'php' => 0.5], ['laravel' => 0.5, 'php' => 0.5]),
            0.000001,
        );
        $this->assertSame(0.0, $service->cosineSimilarity(['laravel' => 1.0], ['mysql' => 1.0]));
        $this->assertSame(0.0, $service->cosineSimilarity([], ['mysql' => 1.0]));
        $this->assertSame(0.0, $service->cosineSimilarity(['mysql' => 1.0], []));
    }

    public function test_weighted_scoring_honors_section_weights(): void
    {
        $service = new MatchingService();
        $company = $this->company();
        $user = $this->user('weighted@example.com');
        $profile = JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => '',
            'summary' => '',
            'location' => '',
        ]);
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $profile->skills()->attach($laravel);

        $job = $this->jobPostingFor($company, [
            'title' => 'Unrelated Role',
            'description' => 'No overlapping summary terms here.',
            'experience_level' => 'junior',
            'location' => 'Damascus',
            'status' => 'open',
            'published_at' => now(),
        ]);
        $job->skills()->attach($laravel);

        $recommendedJobs = $service->recommendJobsForUser($user);

        $this->assertCount(1, $recommendedJobs);
        $this->assertSame(0.5, $recommendedJobs->first()['score']);
        $this->assertSame(1.0, $recommendedJobs->first()['breakdown']['skills']);
        $this->assertSame(0.0, $recommendedJobs->first()['breakdown']['experience']);
        $this->assertSame(0.0, $recommendedJobs->first()['breakdown']['core']);
        $this->assertSame(0.0, $recommendedJobs->first()['breakdown']['education']);
    }

    public function test_empty_sections_do_not_crash_and_produce_zero_scores(): void
    {
        $service = new MatchingService();
        $company = $this->company();
        $user = $this->user('empty@example.com');

        JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => '',
            'summary' => '',
            'location' => '',
        ]);

        $job = $this->jobPostingFor($company, [
            'title' => '',
            'description' => '',
            'employment_type' => '',
            'experience_level' => '',
            'location' => '',
            'status' => 'open',
            'published_at' => now(),
        ]);

        $recommendedJobs = $service->recommendJobsForUser($user);

        $this->assertCount(1, $recommendedJobs);
        $this->assertSame(0.0, $recommendedJobs->first()['score']);
        $this->assertSame([
            'skills' => 0.0,
            'experience' => 0.0,
            'core' => 0.0,
            'education' => 0.0,
        ], $recommendedJobs->first()['breakdown']);
        $this->assertSame([], $recommendedJobs->first()['matched_skills']);
        $this->assertSame($job->id, $recommendedJobs->first()['job']->id);
    }

    private function company(): Company
    {
        return Company::create(['name' => 'Acme Matching Co.', 'approval_status' => 'approved']);
    }

    private function profileWithUser(): JobSeekerProfile
    {
        return JobSeekerProfile::create([
            'user_id' => $this->user()->id,
            'headline' => 'Laravel Backend Developer',
            'summary' => 'Builds APIs and data-heavy services.',
            'location' => 'Remote',
        ]);
    }

    private function user(string $email = 'matching@example.com'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::JOB_SEEKER,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function jobPostingFor(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Platform Engineer',
            'description' => 'Build recruitment workflows.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'salary_min' => 70000,
            'salary_max' => 90000,
            'status' => 'draft',
            'published_at' => null,
        ], $overrides));
    }
}
