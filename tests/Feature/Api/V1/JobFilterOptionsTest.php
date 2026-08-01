<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EmploymentType;
use App\Enums\ExperienceLevel;
use App\Enums\JobWorkMode;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobFilterOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_the_expected_dynamic_contract_in_stable_order(): void
    {
        $city = $this->city('damascus', 'دمشق', 'Damascus');

        $response = $this->getJson('/api/v1/reference/job-filters');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Job filters retrieved successfully.')
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['schema_version', 'filters', 'sort_options'],
            ]);

        $filters = $response->json('data.filters');

        $this->assertSame([
            'city',
            'include_remote',
            'work_mode',
            'employment_type',
            'experience_level',
            'skill',
            'salary',
            'accepting_applications',
        ], array_column($filters, 'key'));

        $this->assertSame([
            'key' => $city->id,
            'value' => 'Damascus',
            'meta' => ['code' => 'damascus'],
        ], $this->filter($filters, 'city')['options'][0]);

        $this->assertSame([
            'parameter' => 'city_id',
            'operator' => 'has_value',
        ], $this->filter($filters, 'include_remote')['visible_when']);

        $this->assertSame([
            'type' => 'remote',
            'endpoint' => '/api/v1/skills',
            'search_parameter' => 'search',
            'value_field' => 'slug',
            'label_field' => 'name',
            'minimum_search_length' => 0,
        ], $this->filter($filters, 'skill')['options_source']);

        $salary = $this->filter($filters, 'salary');
        $this->assertSame(['minimum' => 'salary_min', 'maximum' => 'salary_max'], $salary['parameters']);
        $this->assertSame(['minimum' => 0, 'step' => 1], $salary['constraints']);

        $hiddenFilters = ['location', 'city_code', 'skill_requirement', 'per_page', 'page', 'sort_by', 'sort_direction'];
        $this->assertSame([], array_values(array_intersect($hiddenFilters, array_column($filters, 'key'))));
    }

    public function test_only_active_syrian_cities_are_returned_and_text_is_localized_without_changing_keys(): void
    {
        $damascus = $this->city('damascus', 'دمشق', 'Damascus');
        $this->city('aleppo', 'حلب', 'Aleppo', false);
        City::create([
            'code' => 'beirut',
            'country_code' => 'LB',
            'name_ar' => 'بيروت',
            'name_en' => 'Beirut',
            'is_active' => true,
        ]);

        $english = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/reference/job-filters')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->json('data');

        $arabic = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/reference/job-filters')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'تم جلب فلاتر الوظائف بنجاح.')
            ->json('data');

        $englishCity = $this->filter($english['filters'], 'city');
        $arabicCity = $this->filter($arabic['filters'], 'city');

        $this->assertSame([[
            'key' => $damascus->id,
            'value' => 'Damascus',
            'meta' => ['code' => 'damascus'],
        ]], $englishCity['options']);
        $this->assertSame('دمشق', $arabicCity['options'][0]['value']);

        $this->assertSame(
            array_column($english['filters'], 'key'),
            array_column($arabic['filters'], 'key'),
        );
        $this->assertSame(
            array_column($english['filters'], 'parameter', 'key'),
            array_column($arabic['filters'], 'parameter', 'key'),
        );
        $this->assertNotSame(
            array_column($english['filters'], 'label', 'key'),
            array_column($arabic['filters'], 'label', 'key'),
        );
    }

    public function test_enum_options_and_sort_options_match_values_accepted_by_the_jobs_endpoint(): void
    {
        $city = $this->city('damascus', 'دمشق', 'Damascus');
        $data = $this->getJson('/api/v1/reference/job-filters')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            array_column(JobWorkMode::cases(), 'value'),
            array_column($this->filter($data['filters'], 'work_mode')['options'], 'key'),
        );
        $this->assertSame(
            array_column(EmploymentType::cases(), 'value'),
            array_column($this->filter($data['filters'], 'employment_type')['options'], 'key'),
        );
        $this->assertSame(
            array_column(ExperienceLevel::cases(), 'value'),
            array_column($this->filter($data['filters'], 'experience_level')['options'], 'key'),
        );

        $this->assertSame([
            'newest' => ['sort_by' => 'published_at', 'sort_direction' => 'desc'],
            'oldest' => ['sort_by' => 'published_at', 'sort_direction' => 'asc'],
            'salary_highest' => ['sort_by' => 'salary_max', 'sort_direction' => 'desc'],
            'salary_lowest' => ['sort_by' => 'salary_min', 'sort_direction' => 'asc'],
            'deadline_soonest' => ['sort_by' => 'application_deadline', 'sort_direction' => 'asc'],
        ], array_column($data['sort_options'], 'parameters', 'key'));

        $filterQuery = [
            'city_id' => $city->id,
            'include_remote' => true,
            'work_mode' => JobWorkMode::ON_SITE->value,
            'employment_type' => EmploymentType::FULL_TIME->value,
            'experience_level' => ExperienceLevel::ENTRY_LEVEL->value,
            'skill' => 'php',
            'salary_min' => 0,
            'salary_max' => 1000,
            'accepting_applications' => true,
        ];

        $this->getJson('/api/v1/jobs?'.http_build_query($filterQuery))->assertOk();

        foreach ($data['sort_options'] as $sortOption) {
            $this->getJson('/api/v1/jobs?'.http_build_query($sortOption['parameters']))->assertOk();
        }
    }

    /**
     * @param  list<array<string,mixed>>  $filters
     * @return array<string,mixed>
     */
    private function filter(array $filters, string $key): array
    {
        $filter = collect($filters)->firstWhere('key', $key);

        $this->assertIsArray($filter, "Missing {$key} filter.");

        return $filter;
    }

    private function city(string $code, string $arabic, string $english, bool $active = true): City
    {
        return City::create([
            'code' => $code,
            'country_code' => 'SY',
            'name_ar' => $arabic,
            'name_en' => $english,
            'is_active' => $active,
        ]);
    }
}
