<?php

namespace Tests\Unit\Home;

use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\Home\ProfileCompletenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileCompletenessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_profile_has_zero_percent_and_prioritized_next_item(): void
    {
        [$user, $profile] = $this->profile();

        $result = app(ProfileCompletenessService::class)->calculate(
            $user,
            $this->loaded($profile),
        );

        $this->assertSame(0, $result['percentage']);
        $this->assertFalse($result['is_complete']);
        $this->assertSame('basic_information', $result['next_item']['key']);
        $this->assertContains(
            'skills',
            array_column($result['missing_items'], 'key'),
        );
    }

    public function test_complete_profile_has_100_percent_and_no_next_item(): void
    {
        [$user, $profile] = $this->profile([
            'phone' => '+963900000000',
            'headline' => 'Backend Developer',
            'summary' => 'Builds reliable APIs.',
            'location' => 'Damascus',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Developer',
            'company_name' => 'Workey',
            'start_date' => '2025-01-01',
            'is_current' => true,
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'University',
            'degree' => 'Bachelor',
            'start_date' => '2021-01-01',
        ]);
        $skills = collect(['PHP', 'Laravel', 'Docker'])->map(
            fn (string $name) => Skill::create([
                'name' => $name,
                'slug' => strtolower($name),
            ]),
        );
        $profile->skills()->attach($skills->pluck('id'));
        $cv = CVFile::create([
            'user_id' => $user->id,
            'original_name' => 'cv.pdf',
            'stored_path' => 'cv/test.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
            'confirmed_at' => now(),
        ]);
        $profile->update(['primary_cv_file_id' => $cv->id]);

        $result = app(ProfileCompletenessService::class)->calculate(
            $user,
            $this->loaded($profile),
        );

        $this->assertSame(100, $result['percentage']);
        $this->assertTrue($result['is_complete']);
        $this->assertSame([], $result['missing_items']);
        $this->assertNull($result['next_item']);
    }

    public function test_unconfirmed_primary_cv_and_fewer_than_three_skills_are_missing(): void
    {
        [$user, $profile] = $this->profile([
            'phone' => '+963900000000',
            'headline' => 'Backend Developer',
            'summary' => 'Builds reliable APIs.',
            'location' => 'Damascus',
        ]);
        $skills = collect(['PHP', 'Laravel'])->map(
            fn (string $name) => Skill::create([
                'name' => $name,
                'slug' => strtolower($name),
            ]),
        );
        $profile->skills()->attach($skills->pluck('id'));
        $cv = CVFile::create([
            'user_id' => $user->id,
            'original_name' => 'cv.pdf',
            'stored_path' => 'cv/test.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
            'confirmed_at' => null,
        ]);
        $profile->update(['primary_cv_file_id' => $cv->id]);

        $result = app(ProfileCompletenessService::class)->calculate(
            $user,
            $this->loaded($profile),
        );
        $keys = array_column($result['missing_items'], 'key');

        $this->assertContains('skills', $keys);
        $this->assertContains('confirmed_primary_cv', $keys);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, JobSeekerProfile}
     */
    private function profile(array $attributes = []): array
    {
        $user = User::factory()->create([
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
        ]);
        $profile = JobSeekerProfile::create([
            'user_id' => $user->id,
            ...$attributes,
        ]);

        return [$user, $profile];
    }

    private function loaded(JobSeekerProfile $profile): JobSeekerProfile
    {
        return $profile->fresh()
            ->loadCount(['experiences', 'education', 'skills'])
            ->load('primaryCVFile:id,user_id,confirmed_at,archived_at');
    }
}
