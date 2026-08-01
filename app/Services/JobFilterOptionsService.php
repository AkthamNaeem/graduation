<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Enums\ExperienceLevel;
use App\Enums\JobWorkMode;
use App\Models\City;
use App\Support\LocalizedValue;

class JobFilterOptionsService
{
    /**
     * @return array{schema_version:int,filters:list<array<string,mixed>>,sort_options:list<array<string,mixed>>}
     */
    public function getSchema(): array
    {
        return [
            'schema_version' => 1,
            'filters' => [
                [
                    'key' => 'city',
                    'label' => __('job_filters.filters.city'),
                    'type' => 'single_select',
                    'parameter' => 'city_id',
                    'clearable' => true,
                    'default' => null,
                    'options' => $this->cityOptions(),
                ],
                [
                    'key' => 'include_remote',
                    'label' => __('job_filters.filters.include_remote'),
                    'type' => 'boolean',
                    'parameter' => 'include_remote',
                    'default' => false,
                    'visible_when' => [
                        'parameter' => 'city_id',
                        'operator' => 'has_value',
                    ],
                ],
                $this->enumFilter('work_mode', 'work_mode', JobWorkMode::cases(), 'job_work_modes'),
                $this->enumFilter('employment_type', 'employment_type', EmploymentType::cases(), 'employment_types'),
                $this->enumFilter('experience_level', 'experience_level', ExperienceLevel::cases(), 'experience_levels'),
                [
                    'key' => 'skill',
                    'label' => __('job_filters.filters.skill'),
                    'type' => 'autocomplete',
                    'parameter' => 'skill',
                    'clearable' => true,
                    'default' => null,
                    'options_source' => [
                        'type' => 'remote',
                        'endpoint' => '/api/v1/skills',
                        'search_parameter' => 'search',
                        'value_field' => 'slug',
                        'label_field' => 'name',
                        'minimum_search_length' => 0,
                    ],
                ],
                [
                    'key' => 'salary',
                    'label' => __('job_filters.filters.salary'),
                    'type' => 'range',
                    'parameters' => [
                        'minimum' => 'salary_min',
                        'maximum' => 'salary_max',
                    ],
                    'default' => [
                        'minimum' => null,
                        'maximum' => null,
                    ],
                    'constraints' => [
                        'minimum' => 0,
                        'step' => 1,
                    ],
                ],
                [
                    'key' => 'accepting_applications',
                    'label' => __('job_filters.filters.accepting_applications'),
                    'type' => 'boolean',
                    'parameter' => 'accepting_applications',
                    'default' => true,
                ],
            ],
            'sort_options' => $this->sortOptions(),
        ];
    }

    /** @return list<array{key:int,value:string,meta:array{code:string}}> */
    private function cityOptions(): array
    {
        return City::query()
            ->where('country_code', 'SY')
            ->where('is_active', true)
            ->orderBy(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en')
            ->get(['id', 'code', 'name_ar', 'name_en'])
            ->map(fn (City $city): array => [
                'key' => $city->id,
                'value' => $city->localizedName(),
                'meta' => ['code' => $city->code],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string,mixed>
     */
    private function enumFilter(string $key, string $parameter, array $cases, string $translationGroup): array
    {
        return [
            'key' => $key,
            'label' => __('job_filters.filters.'.$key),
            'type' => 'single_select',
            'parameter' => $parameter,
            'clearable' => true,
            'default' => null,
            'options' => array_map(
                fn (\BackedEnum $case): array => LocalizedValue::make($case, $translationGroup),
                $cases,
            ),
        ];
    }

    /** @return list<array{key:string,value:string,parameters:array{sort_by:string,sort_direction:string}}> */
    private function sortOptions(): array
    {
        $definitions = [
            'newest' => ['published_at', 'desc'],
            'oldest' => ['published_at', 'asc'],
            'salary_highest' => ['salary_max', 'desc'],
            'salary_lowest' => ['salary_min', 'asc'],
            'deadline_soonest' => ['application_deadline', 'asc'],
        ];

        return array_map(
            fn (string $key, array $parameters): array => [
                'key' => $key,
                'value' => __('job_filters.sort_options.'.$key),
                'parameters' => [
                    'sort_by' => $parameters[0],
                    'sort_direction' => $parameters[1],
                ],
            ],
            array_keys($definitions),
            array_values($definitions),
        );
    }
}
