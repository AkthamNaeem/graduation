<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_seeker_receives_the_aggregated_localized_profile_page(): void
    {
        $city = City::create([
            'code' => 'as_suwayda',
            'country_code' => 'SY',
            'name_ar' => 'السويداء',
            'name_en' => 'As-Suwayda',
            'is_active' => true,
        ]);
        $user = $this->jobSeeker('أكثم نعيم', [
            'headline' => 'Backend Developer',
            'summary' => 'Laravel developer',
            'phone' => '+963123',
            'location' => 'السويداء، سوريا',
            'city_id' => $city->id,
            'github_url' => 'https://github.com/example',
            'linkedin_url' => 'https://linkedin.com/in/example',
            'portfolio_url' => null,
        ]);

        Experience::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'Older Role',
            'company_name' => 'First Co',
            'start_date' => '2023-01-01',
            'end_date' => '2023-12-31',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'Current Role',
            'company_name' => 'Current Co',
            'start_date' => '2023-06-01',
            'is_current' => true,
        ]);
        Education::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'institution' => 'Older University',
            'end_date' => '2020-01-01',
        ]);
        Education::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'institution' => 'Recent University',
            'end_date' => '2024-01-01',
        ]);
        $php = Skill::create(['name' => 'PHP', 'slug' => 'php']);
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $user->jobSeekerProfile->skills()->attach([$php->id, $laravel->id]);

        $response = $this->withHeader('Accept-Language', 'ar')
            ->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب صفحة الملف الشخصي بنجاح.')
            ->assertJsonPath('data.identity.id', $user->id)
            ->assertJsonPath('data.identity.profile_id', $user->jobSeekerProfile->id)
            ->assertJsonPath('data.identity.name', 'أكثم نعيم')
            ->assertJsonPath('data.identity.email', $user->email)
            ->assertJsonPath('data.identity.city.name', 'السويداء')
            ->assertJsonPath('data.identity.avatar.initials', 'أ ن')
            ->assertJsonPath('data.career_summary.experiences_count', 2)
            ->assertJsonPath('data.career_summary.education_count', 2)
            ->assertJsonPath('data.career_summary.skills_count', 2)
            ->assertJsonPath('data.career_summary.professional_links_count', 2)
            ->assertJsonPath('data.experiences.0.title', 'Current Role')
            ->assertJsonPath('data.education.0.institution', 'Recent University')
            ->assertJsonPath('data.skills.0.name', 'Laravel')
            ->assertJsonPath('data.professional_links.0.type.key', 'github')
            ->assertJsonPath('data.professional_links.0.type.label', 'Git هب')
            ->assertJsonPath('data.professional_links.1.type.key', 'linkedin')
            ->assertJsonCount(2, 'data.professional_links')
            ->assertJsonPath('data.allowed_actions', [
                'edit_profile',
                'manage_experiences',
                'manage_education',
                'manage_skills',
                'manage_links',
            ])
            ->assertJsonMissingPath('data.experiences.0.source_cv_file_id')
            ->assertJsonMissingPath('data.skills.0.source_cv_file_id');

        $this->assertIsFloat($response->json('data.career_summary.years_of_experience'));
    }

    public function test_english_profile_link_labels_are_localized_and_empty_links_are_omitted(): void
    {
        $user = $this->jobSeeker('John Doe', [
            'github_url' => null,
            'linkedin_url' => '',
            'portfolio_url' => 'https://example.com',
        ]);

        $this->withHeader('Accept-Language', 'en')
            ->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('message', 'Profile page retrieved successfully.')
            ->assertJsonPath('data.identity.avatar.initials', 'J D')
            ->assertJsonCount(1, 'data.professional_links')
            ->assertJsonPath('data.professional_links.0.type.key', 'portfolio')
            ->assertJsonPath('data.professional_links.0.type.label', 'Portfolio');

    }

    public function test_duplicate_professional_urls_are_returned_once_in_priority_order(): void
    {
        $user = $this->jobSeeker('Duplicate Links', [
            'github_url' => 'https://example.com/same',
            'linkedin_url' => 'https://example.com/same',
        ]);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonCount(1, 'data.professional_links')
            ->assertJsonPath('data.professional_links.0.type.key', 'github');
    }

    public function test_profile_update_changes_name_and_profile_atomically_and_returns_full_contract(): void
    {
        $user = $this->jobSeeker('Old Name');

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/profile', [
                'name' => 'New Name',
                'headline' => 'Senior Backend Developer',
                'github_url' => 'https://github.com/new-name',
            ])
            ->assertOk()
            ->assertJsonPath('data.identity.name', 'New Name')
            ->assertJsonPath('data.identity.headline', 'Senior Backend Developer')
            ->assertJsonPath('data.identity.avatar.initials', 'N N')
            ->assertJsonPath('data.professional_links.0.type.key', 'github')
            ->assertJsonStructure(['data' => [
                'identity', 'career_summary', 'professional_profile', 'experiences',
                'education', 'skills', 'professional_links', 'allowed_actions',
            ]]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
        $this->assertDatabaseHas('job_seeker_profiles', [
            'user_id' => $user->id,
            'headline' => 'Senior Backend Developer',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'profile.updated',
            'entity_type' => JobSeekerProfile::class,
            'entity_id' => $user->jobSeekerProfile->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_profile_update_can_change_only_the_name(): void
    {
        $user = $this->jobSeeker('Old Name', ['headline' => 'Keep Me']);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/profile', ['name' => 'Name Only'])
            ->assertOk()
            ->assertJsonPath('data.identity.name', 'Name Only')
            ->assertJsonPath('data.identity.headline', 'Keep Me');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Name Only']);
        $this->assertDatabaseHas('job_seeker_profiles', [
            'user_id' => $user->id,
            'headline' => 'Keep Me',
        ]);
    }

    public function test_invalid_update_does_not_change_name_or_profile_and_nullable_link_can_be_cleared(): void
    {
        $user = $this->jobSeeker('Original Name', [
            'headline' => 'Original Headline',
            'github_url' => 'https://github.com/original',
        ]);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/profile', [
                'name' => 'Changed Name',
                'headline' => ['not a string'],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Original Name']);
        $this->assertDatabaseHas('job_seeker_profiles', [
            'user_id' => $user->id,
            'headline' => 'Original Headline',
        ]);

        $this->withToken($this->tokenFor($user))
            ->putJson('/api/v1/profile', ['github_url' => null])
            ->assertOk()
            ->assertJsonCount(0, 'data.professional_links');
    }

    public function test_guest_employer_and_administrator_cannot_access_job_seeker_profile(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->putJson('/api/v1/profile', ['name' => 'No Access'])->assertUnauthorized();

        foreach ([UserRole::EMPLOYER, UserRole::ADMIN] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $token = $this->tokenFor($user);

            $this->withToken($token)->getJson('/api/v1/profile')->assertForbidden();
            $this->withToken($token)->putJson('/api/v1/profile', ['name' => 'No Access'])->assertForbidden();
        }
    }

    public function test_profile_query_count_is_constant_as_nested_records_grow(): void
    {
        $user = $this->jobSeeker('Query Test');
        $token = $this->tokenFor($user);
        Experience::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'One',
            'company_name' => 'One Co',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
        $singleCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 12) as $number) {
            Experience::create([
                'job_seeker_profile_id' => $user->jobSeekerProfile->id,
                'title' => "Role {$number}",
                'company_name' => "Company {$number}",
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($singleCount + 1, $manyCount);
    }

    /** @param array<string, mixed> $profile */
    private function jobSeeker(string $name, array $profile = []): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => UserRole::JOB_SEEKER,
        ]);
        JobSeekerProfile::create(array_merge([
            'user_id' => $user->id,
            'headline' => 'Backend Developer',
        ], $profile));

        return $user->load('jobSeekerProfile');
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
