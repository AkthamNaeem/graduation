<?php

namespace Tests\Unit;

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

class ProfilePageCompletenessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_profile_has_zero_percent_and_prioritized_granular_missing_items(): void
    {
        [$user, $profile] = $this->profile(['name' => ''], []);

        $result = $this->calculate($user, $profile);

        $this->assertSame(0, $result['percentage']);
        $this->assertFalse($result['is_complete']);
        $this->assertSame(0, $result['completed_items_count']);
        $this->assertSame(8, $result['missing_items_count']);
        $this->assertSame([
            'basic_information',
            'professional_headline',
            'professional_summary',
            'location',
            'experience',
            'education',
            'skills',
            'confirmed_cv',
        ], array_column($result['missing_items'], 'key'));
        $this->assertSame('basic_information', $result['next_item']['key']);
    }

    public function test_professional_headline_and_summary_are_reported_separately(): void
    {
        [$user, $profile] = $this->profile([], [
            'phone' => '+963111',
            'location' => 'Damascus',
        ]);

        $result = $this->calculate($user, $profile);

        $this->assertContains('professional_headline', array_column($result['missing_items'], 'key'));
        $this->assertContains('professional_summary', array_column($result['missing_items'], 'key'));
        $this->assertNotContains('professional_profile', array_column($result['missing_items'], 'key'));
    }

    public function test_complete_profile_reaches_100_without_optional_professional_links(): void
    {
        [$user, $profile] = $this->completeProfile();

        $result = $this->calculate($user, $profile);

        $this->assertSame(100, $result['percentage']);
        $this->assertTrue($result['is_complete']);
        $this->assertSame(7, $result['completed_items_count']);
        $this->assertSame(0, $result['missing_items_count']);
        $this->assertNull($result['next_item']);
        $this->assertSame(
            ['github_link', 'linkedin_link', 'portfolio_link'],
            array_column($result['recommended_items'], 'key'),
        );
    }

    public function test_skills_require_three_items_and_location_accepts_city_or_text(): void
    {
        [$user, $profile] = $this->profile([], [
            'phone' => '+963111',
            'headline' => 'Backend Developer',
            'summary' => 'Laravel developer',
            'location' => 'Damascus',
        ]);
        $skills = collect(['PHP', 'Laravel'])->map(fn (string $name) => Skill::create([
            'name' => $name,
            'slug' => strtolower($name),
        ]));
        $profile->skills()->attach($skills->pluck('id'));

        $result = $this->calculate($user, $profile);

        $this->assertNotContains('location', array_column($result['missing_items'], 'key'));
        $this->assertContains('skills', array_column($result['missing_items'], 'key'));
    }

    public function test_newer_pending_cv_does_not_negate_an_older_usable_confirmed_cv(): void
    {
        [$user, $profile] = $this->completeProfile();
        CVFile::create($this->cvAttributes($user, [
            'status' => 'processing',
            'confirmed_at' => null,
        ]));

        $result = $this->calculate($user, $profile);

        $this->assertSame(100, $result['percentage']);
        $this->assertNotContains('confirmed_cv', array_column($result['missing_items'], 'key'));
    }

    public function test_confirmed_failed_cv_is_not_counted_as_complete(): void
    {
        [$user, $profile] = $this->completeProfile();
        CVFile::query()->where('user_id', $user->id)->update(['status' => 'failed']);

        $result = $this->calculate($user, $profile);

        $this->assertSame(90, $result['percentage']);
        $this->assertSame(['confirmed_cv'], array_column($result['missing_items'], 'key'));
    }

    public function test_each_required_group_removes_only_its_own_weight_when_missing(): void
    {
        [$user, $profile] = $this->completeProfile();

        $user->update(['name' => '']);
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'basic_information', 85);
        $user->update(['name' => 'Complete User']);

        $profile->update(['phone' => null]);
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'basic_information', 85);
        $profile->update(['phone' => '+963111']);

        $profile->update(['headline' => null]);
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'professional_headline', 85);
        $profile->update(['headline' => 'Backend Developer', 'summary' => null]);
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'professional_summary', 85);
        $profile->update(['summary' => 'Laravel developer', 'location' => null]);
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'location', 90);
        $profile->update(['location' => 'Damascus']);

        $experience = $profile->experiences()->firstOrFail();
        $experience->delete();
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'experience', 80);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Developer',
            'company_name' => 'Workey',
        ]);

        $education = $profile->education()->firstOrFail();
        $education->delete();
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'education', 85);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Damascus University',
        ]);

        $profile->skills()->detach($profile->skills()->firstOrFail()->id);
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'skills', 85);

        $profile->skills()->attach(Skill::create([
            'name' => 'REST',
            'slug' => 'rest',
        ])->id);
        CVFile::query()->where('user_id', $user->id)->update(['confirmed_at' => null]);
        $this->assertMissingOnly($this->calculate($user->fresh(), $profile), 'confirmed_cv', 90);
    }

    /** @return array{User, JobSeekerProfile} */
    private function profile(array $userAttributes = [], array $profileAttributes = []): array
    {
        $user = User::factory()->create(array_merge([
            'role' => UserRole::JOB_SEEKER,
        ], $userAttributes));
        $profile = JobSeekerProfile::create(array_merge([
            'user_id' => $user->id,
        ], $profileAttributes));

        return [$user, $profile];
    }

    /** @return array{User, JobSeekerProfile} */
    private function completeProfile(): array
    {
        [$user, $profile] = $this->profile([], [
            'phone' => '+963111',
            'headline' => 'Backend Developer',
            'summary' => 'Laravel developer',
            'location' => 'Damascus',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Developer',
            'company_name' => 'Workey',
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Damascus University',
        ]);
        foreach (['PHP', 'Laravel', 'SQL'] as $name) {
            $skill = Skill::create(['name' => $name, 'slug' => strtolower($name)]);
            $profile->skills()->attach($skill->id);
        }
        CVFile::create($this->cvAttributes($user, [
            'status' => 'parsed',
            'confirmed_at' => now()->subMinute(),
        ]));

        return [$user, $profile];
    }

    /** @param array<string, mixed> $overrides */
    private function cvAttributes(User $user, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'cvs/resume.pdf',
            'disk' => 'local',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'status' => 'parsed',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function calculate(User $user, JobSeekerProfile $profile): array
    {
        $loaded = $profile->fresh()->load([
            'experiences',
            'education',
            'skills',
            'latestConfirmedCVFile',
        ]);

        return app(ProfileCompletenessService::class)->calculateForProfilePage($user, $loaded);
    }

    /** @param array<string, mixed> $result */
    private function assertMissingOnly(array $result, string $key, int $percentage): void
    {
        $this->assertSame($percentage, $result['percentage']);
        $this->assertSame([$key], array_column($result['missing_items'], 'key'));
        $this->assertSame($key, $result['next_item']['key']);
    }
}
