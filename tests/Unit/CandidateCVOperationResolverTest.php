<?php

namespace Tests\Unit;

use App\Enums\CandidateCVOperation;
use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\CV\CandidateCVOperationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateCVOperationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_and_email_without_professional_data_is_initial_upload(): void
    {
        [$user, $profile] = $this->candidate();

        $this->assertSame(
            CandidateCVOperation::INITIAL_UPLOAD,
            app(CandidateCVOperationResolver::class)->resolve($user, $profile),
        );
    }

    public function test_each_meaningful_profile_source_makes_the_operation_an_update(): void
    {
        [$headlineUser, $headlineProfile] = $this->candidate();
        $headlineProfile->update(['headline' => 'Backend Engineer']);
        $this->assertUpdate($headlineUser, $headlineProfile);

        [$experienceUser, $experienceProfile] = $this->candidate();
        Experience::create([
            'job_seeker_profile_id' => $experienceProfile->id,
            'title' => 'Engineer',
            'company_name' => 'Acme',
        ]);
        $this->assertUpdate($experienceUser, $experienceProfile->refresh());

        [$skillUser, $skillProfile] = $this->candidate();
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $skillProfile->skills()->attach($skill->id);
        $this->assertUpdate($skillUser, $skillProfile->refresh());
    }

    public function test_valid_current_cv_makes_the_operation_an_update(): void
    {
        [$user, $profile] = $this->candidate();
        $cv = CVFile::create([
            'user_id' => $user->id,
            'original_name' => 'current.pdf',
            'stored_path' => 'cv-files/current.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
            'review_mode' => CVFile::REVIEW_MODE_INITIAL_IMPORT,
            'review_status' => CVFile::REVIEW_STATUS_APPLIED,
            'confirmed_at' => now(),
        ]);
        $profile->update(['primary_cv_file_id' => $cv->id]);

        $this->assertUpdate($user, $profile->refresh());
    }

    private function assertUpdate(User $user, JobSeekerProfile $profile): void
    {
        $this->assertSame(
            CandidateCVOperation::UPDATE,
            app(CandidateCVOperationResolver::class)->resolve($user, $profile),
        );
    }

    /** @return array{User, JobSeekerProfile} */
    private function candidate(): array
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER->value]);
        $profile = JobSeekerProfile::create(['user_id' => $user->id]);

        return [$user, $profile];
    }
}
