<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\City;
use App\Models\Company;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CitySupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_city_reference_api_is_localized_searchable_and_active_by_default(): void
    {
        $damascus = $this->city('damascus', 'دمشق', 'Damascus');
        $this->city('aleppo', 'حلب', 'Aleppo', false);
        City::create([
            'code' => 'beirut', 'country_code' => 'LB', 'name_ar' => 'بيروت',
            'name_en' => 'Beirut', 'is_active' => true,
        ]);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/reference/cities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $damascus->id)
            ->assertJsonPath('data.0.name', 'دمشق')
            ->assertHeader('Content-Language', 'ar');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/reference/cities?search=Dam')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Damascus');

        $this->getJson('/api/v1/reference/cities?active_only=false')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_job_seeker_registration_can_create_profile_with_city_and_legacy_payload_stays_optional(): void
    {
        $damascus = $this->city('damascus', 'دمشق', 'Damascus');

        $this->postJson('/api/v1/auth/register/job-seeker', [
            'name' => 'City Applicant',
            'email' => 'city-registration@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
            'location' => 'Damascus city centre',
            'city_id' => $damascus->id,
        ])->assertCreated()
            ->assertJsonPath('data.user.job_seeker_profile.location', 'Damascus city centre')
            ->assertJsonPath('data.user.job_seeker_profile.city.code', 'damascus');

        $this->postJson('/api/v1/auth/register/job-seeker', [
            'name' => 'Legacy Applicant',
            'email' => 'legacy-registration@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.user.job_seeker_profile.city', null);
    }

    public function test_job_seeker_can_set_change_and_remove_city_without_breaking_legacy_location(): void
    {
        $damascus = $this->city('damascus', 'دمشق', 'Damascus');
        $aleppo = $this->city('aleppo', 'حلب', 'Aleppo');
        $inactive = $this->city('homs', 'حمص', 'Homs', false);
        $user = $this->jobSeeker();

        $this->withToken($this->tokenFor($user))->putJson('/api/v1/profile', [
            'location' => 'المزة، قرب ساحة المحافظة',
            'city_id' => $damascus->id,
        ])->assertOk()
            ->assertJsonPath('data.location', 'المزة، قرب ساحة المحافظة')
            ->assertJsonPath('data.city.code', 'damascus')
            ->assertJsonPath('data.city.name', 'Damascus');

        $this->withToken($this->tokenFor($user))->putJson('/api/v1/profile', [
            'city_id' => $aleppo->id,
        ])->assertOk()->assertJsonPath('data.city.code', 'aleppo');

        $this->withToken($this->tokenFor($user))->putJson('/api/v1/profile', [
            'city_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.location', 'المزة، قرب ساحة المحافظة');

        $this->withToken($this->tokenFor($user))->putJson('/api/v1/profile', [
            'location' => 'سوريا',
        ])->assertOk()->assertJsonPath('data.location', 'سوريا');

        $this->withToken($this->tokenFor($user))->putJson('/api/v1/profile', [
            'city_id' => $inactive->id,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'CITY_INACTIVE')
            ->assertJsonValidationErrors(['city_id']);

        $this->withToken($this->tokenFor($user))->putJson('/api/v1/profile', [
            'city_id' => 999999,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'CITY_NOT_FOUND')
            ->assertJsonValidationErrors(['city_id']);
    }

    public function test_job_city_is_created_updated_localized_and_remote_remains_optional(): void
    {
        $damascus = $this->city('damascus', 'دمشق', 'Damascus');
        $aleppo = $this->city('aleppo', 'حلب', 'Aleppo');
        $employer = $this->employer();

        $response = $this->withHeader('Accept-Language', 'ar')
            ->withToken($this->tokenFor($employer))
            ->postJson('/api/v1/jobs', $this->jobPayload([
                'location' => 'دمشق، شارع الثورة',
                'city_id' => $damascus->id,
                'work_mode' => 'on_site',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.city.name', 'دمشق');

        $jobId = $response->json('data.id');
        $this->withToken($this->tokenFor($employer))->putJson("/api/v1/jobs/{$jobId}", [
            'city_id' => $aleppo->id,
        ])->assertOk()->assertJsonPath('data.city.code', 'aleppo');

        $this->withToken($this->tokenFor($employer))->postJson('/api/v1/jobs', $this->jobPayload([
            'location' => null,
            'city_id' => null,
            'work_mode' => 'remote',
        ]))->assertCreated()->assertJsonPath('data.city', null);
    }

    public function test_public_search_uses_structured_city_and_preserves_other_filters_and_sorting(): void
    {
        $damascus = $this->city('damascus', 'دمشق', 'Damascus');
        $aleppo = $this->city('aleppo', 'حلب', 'Aleppo');
        $company = Company::create(['name' => 'Search Co', 'approval_status' => 'approved']);

        $structured = $this->job($company, [
            'title' => 'Damascus Structured', 'city_id' => $damascus->id,
            'location' => 'Aleppo written in legacy text', 'status' => 'open',
            'published_at' => now()->subDay(),
        ]);
        $this->job($company, [
            'title' => 'Aleppo Structured', 'city_id' => $aleppo->id,
            'location' => 'Damascus written in legacy text', 'status' => 'open',
            'published_at' => now()->subHours(2),
        ]);
        $this->job($company, [
            'title' => 'Remote Job', 'city_id' => null, 'location' => null,
            'work_mode' => 'remote', 'status' => 'open', 'published_at' => now(),
        ]);
        $this->job($company, [
            'title' => 'Hidden Draft', 'city_id' => $damascus->id, 'status' => 'draft',
        ]);

        $this->getJson("/api/v1/jobs?city_id={$damascus->id}&sort_by=title&sort_direction=asc&per_page=10")
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $structured->id)
            ->assertJsonPath('data.meta.current_page', 1);

        $this->getJson('/api/v1/jobs?city_code=damascus&include_remote=true')
            ->assertOk()->assertJsonCount(2, 'data.data');

        $this->getJson('/api/v1/jobs?search=Damascus')
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $structured->id);
    }

    public function test_cv_city_detection_creates_review_suggestion_without_overwriting_manual_city(): void
    {
        $damascus = $this->city('damascus', 'دمشق', 'Damascus');
        $aleppo = $this->city('aleppo', 'حلب', 'Aleppo');
        $user = $this->jobSeeker();
        $user->jobSeekerProfile->update(['city_id' => $aleppo->id]);
        $cv = $this->parsedCV($user, 'Damascus, Syria');

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cv->id}/suggestions/generate")
            ->assertCreated();

        $suggestion = ProfileChangeSuggestion::query()
            ->where('entity_type', ProfileChangeSuggestion::ENTITY_PROFILE)
            ->whereJsonContains('new_value->city_id', $damascus->id)
            ->firstOrFail();

        $this->assertSame(ProfileChangeSuggestion::TYPE_UPDATE, $suggestion->suggestion_type);
        $this->assertSame($aleppo->id, $user->jobSeekerProfile->refresh()->city_id);
        $this->assertSame(ProfileChangeSuggestion::STATUS_PENDING, $suggestion->status);
    }

    public function test_ambiguous_or_unknown_cv_location_does_not_guess_a_city(): void
    {
        $this->city('damascus', 'دمشق', 'Damascus');
        $this->city('aleppo', 'حلب', 'Aleppo');
        $user = $this->jobSeeker();
        $cv = $this->parsedCV($user, 'Syria - willing to relocate');

        $this->withToken($this->tokenFor($user))
            ->postJson("/api/v1/cv/{$cv->id}/suggestions/generate")
            ->assertCreated();

        $this->assertFalse(ProfileChangeSuggestion::query()
            ->where('entity_type', ProfileChangeSuggestion::ENTITY_PROFILE)
            ->get()
            ->contains(fn (ProfileChangeSuggestion $item): bool => array_key_exists('city_id', $item->new_value)));
    }

    private function city(string $code, string $ar, string $en, bool $active = true): City
    {
        return City::create([
            'code' => $code, 'country_code' => 'SY', 'name_ar' => $ar,
            'name_en' => $en, 'is_active' => $active,
        ]);
    }

    private function jobSeeker(string $email = 'city-seeker@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $user->id, 'headline' => 'Backend Developer']);

        return $user->load('jobSeekerProfile');
    }

    private function employer(): User
    {
        $company = Company::create(['name' => 'City Employer', 'approval_status' => 'approved']);
        $user = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $user->id, 'company_id' => $company->id]);

        return $user->load('employerProfile.company');
    }

    /** @param array<string, mixed> $overrides */
    private function jobPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Developer', 'description' => 'Build APIs.',
            'requirements' => 'Laravel experience.', 'employment_type' => 'full_time',
            'experience_level' => 'mid_level', 'work_mode' => 'on_site',
            'location' => 'Damascus',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function job(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $company->id, 'title' => 'Job', 'description' => 'Description',
            'requirements' => 'Requirements', 'employment_type' => 'full_time',
            'experience_level' => 'mid_level', 'location' => 'Damascus',
            'work_mode' => 'on_site', 'status' => 'draft', 'published_at' => null,
        ], $overrides));
    }

    private function parsedCV(User $user, string $location): CVFile
    {
        $cv = CVFile::create([
            'user_id' => $user->id, 'original_name' => 'resume.pdf',
            'stored_path' => 'cv/'.$user->id.'/resume.pdf', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'extension' => 'pdf',
            'size_bytes' => 1024, 'status' => 'parsed',
        ]);
        CVParsingResult::create([
            'cv_file_id' => $cv->id, 'raw_text' => $location,
            'parsed_json' => [
                'phone' => null, 'summary' => null, 'location' => $location,
                'experience' => [], 'education' => [], 'skills' => [],
            ],
        ]);

        return $cv;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
