<?php

namespace App\Services\CV;

use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;

class CVProfileSnapshotService
{
    /** @return array<string, mixed> */
    public function snapshot(JobSeekerProfile $profile): array
    {
        $profile->loadMissing(['experiences', 'education', 'skills']);

        return [
            'profile' => collect($profile->getAttributes())->only([
                'headline',
                'summary',
                'phone',
                'location',
                'city_id',
                'portfolio_url',
                'linkedin_url',
                'github_url',
            ])->all(),
            'experience' => $profile->experiences
                ->sortBy('id')
                ->map(fn (Experience $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'company_name' => $item->company_name,
                    'location' => $item->location,
                    'start_date' => $item->start_date?->format('Y-m-d'),
                    'end_date' => $item->end_date?->format('Y-m-d'),
                    'is_current' => $item->is_current,
                    'description' => $item->description,
                ])->values()->all(),
            'education' => $profile->education
                ->sortBy('id')
                ->map(fn (Education $item): array => [
                    'id' => $item->id,
                    'institution' => $item->institution,
                    'degree' => $item->degree,
                    'field_of_study' => $item->field_of_study,
                    'start_date' => $item->start_date?->format('Y-m-d'),
                    'end_date' => $item->end_date?->format('Y-m-d'),
                    'description' => $item->description,
                ])->values()->all(),
            'skills' => $profile->skills
                ->sortBy('id')
                ->map(fn (Skill $skill): string => $skill->name)
                ->values()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function currentHash(JobSeekerProfile $profile): string
    {
        return $this->hash($this->snapshot($profile));
    }
}
