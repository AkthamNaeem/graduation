<?php

namespace Tests\Unit;

use App\Models\City;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\LocationCompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationCompatibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_different_remote_and_missing_city_are_explainable_and_non_decisive(): void
    {
        $damascus = $this->city('damascus');
        $aleppo = $this->city('aleppo');
        $profile = JobSeekerProfile::create([
            'user_id' => User::factory()->create()->id,
            'city_id' => $damascus->id,
        ]);
        $company = Company::create(['name' => 'Compatibility Co']);
        $service = new LocationCompatibilityService;

        $same = $service->evaluate($this->job($company, 'on_site', $damascus->id), $profile, 5);
        $different = $service->evaluate($this->job($company, 'hybrid', $aleppo->id), $profile, 5);
        $remote = $service->evaluate($this->job($company, 'remote', $aleppo->id), $profile, 5);
        $missing = $service->evaluate($this->job($company, 'on_site', null), $profile, 5);

        $this->assertSame(['same_city', 5.0, 'SAME_CITY'], [$same['status'], $same['score'], $same['reason_code']]);
        $this->assertSame(['different_city', 0.0, 'DIFFERENT_CITY'], [$different['status'], $different['score'], $different['reason_code']]);
        $this->assertSame(['remote', 5.0, 'REMOTE_LOCATION_COMPATIBLE'], [$remote['status'], $remote['score'], $remote['reason_code']]);
        $this->assertSame(['missing', 2.5, 'LOCATION_DATA_MISSING'], [$missing['status'], $missing['score'], $missing['reason_code']]);
        $this->assertArrayNotHasKey('accepted', $same);
        $this->assertArrayNotHasKey('rejected', $different);
    }

    private function city(string $code): City
    {
        return City::create([
            'code' => $code, 'country_code' => 'SY', 'name_ar' => $code,
            'name_en' => ucfirst($code), 'is_active' => true,
        ]);
    }

    private function job(Company $company, string $mode, ?int $cityId): JobPosting
    {
        return JobPosting::create([
            'company_id' => $company->id, 'title' => 'Job', 'description' => 'Description',
            'employment_type' => 'full_time', 'experience_level' => 'mid_level',
            'work_mode' => $mode, 'city_id' => $cityId,
        ]);
    }
}
