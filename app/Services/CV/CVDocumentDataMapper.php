<?php

namespace App\Services\CV;

use App\Models\JobSeekerProfile;

class CVDocumentDataMapper
{
    /** @return array<string, mixed> */
    public function fromProfile(JobSeekerProfile $profile): array
    {
        $profile->loadMissing(['user', 'city', 'experiences', 'education', 'skills']);

        return [
            'name' => $this->text($profile->user?->name),
            'headline' => $this->text($profile->headline),
            'summary' => $this->text($profile->summary),
            'contact' => [
                'email' => $this->text($profile->user?->email),
                'phone' => $this->text($profile->phone),
                'location' => $this->location(
                    $profile->location,
                    $profile->city?->name_ar,
                    $profile->city?->name_en,
                ),
            ],
            'links' => $this->links([
                'portfolio' => $profile->portfolio_url,
                'linkedin' => $profile->linkedin_url,
                'github' => $profile->github_url,
            ]),
            'experiences' => $profile->experiences->map(fn ($experience): array => [
                'title' => $this->text($experience->title),
                'company' => $this->text($experience->company_name),
                'location' => $this->text($experience->location),
                'start_date' => $experience->start_date?->toDateString(),
                'end_date' => $experience->end_date?->toDateString(),
                'is_current' => (bool) $experience->is_current,
                'description' => $this->text($experience->description),
            ])->values()->all(),
            'education' => $profile->education->map(fn ($education): array => [
                'institution' => $this->text($education->institution),
                'degree' => $this->text($education->degree),
                'field_of_study' => $this->text($education->field_of_study),
                'start_date' => $education->start_date?->toDateString(),
                'end_date' => $education->end_date?->toDateString(),
                'description' => $this->text($education->description),
            ])->values()->all(),
            'skills' => $profile->skills
                ->map(fn ($skill): ?string => $this->text($skill->name))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * Map only the immutable snapshot payload. Missing snapshot fields intentionally
     * remain missing and are never filled from the candidate's live profile.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function fromSnapshot(array $snapshot): array
    {
        $identity = is_array($snapshot['identity'] ?? null) ? $snapshot['identity'] : [];
        $location = is_array($snapshot['location'] ?? null) ? $snapshot['location'] : [];
        $city = is_array($location['city'] ?? null) ? $location['city'] : [];
        $professionalLinks = is_array($snapshot['professional_links'] ?? null)
            ? $snapshot['professional_links']
            : [];

        return [
            'name' => $this->text($identity['name'] ?? null),
            'headline' => $this->text($identity['headline'] ?? null),
            'summary' => $this->text($identity['summary'] ?? null),
            'contact' => [
                'email' => $this->text($identity['email'] ?? null),
                'phone' => $this->text($identity['phone'] ?? null),
                'location' => $this->location(
                    $location['location_text'] ?? null,
                    $city['name_ar'] ?? null,
                    $city['name_en'] ?? null,
                ),
            ],
            'links' => $this->links([
                'portfolio' => $professionalLinks['portfolio_url'] ?? null,
                'linkedin' => $professionalLinks['linkedin_url'] ?? null,
                'github' => $professionalLinks['github_url'] ?? null,
            ]),
            'experiences' => $this->snapshotExperiences($snapshot['experiences'] ?? null),
            'education' => $this->snapshotEducation($snapshot['education'] ?? null),
            'skills' => $this->snapshotSkills($snapshot['skills'] ?? null),
        ];
    }

    /** @return list<array{label: string, url: string}> */
    private function links(array $links): array
    {
        $result = [];
        foreach ($links as $type => $url) {
            $url = $this->text($url);
            if ($url === null) {
                continue;
            }

            $result[] = [
                'label' => __("profile.links.{$type}"),
                'url' => $url,
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function snapshotExperiences(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(fn (array $item): array => [
            'title' => $this->text($item['job_title'] ?? $item['title'] ?? null),
            'company' => $this->text($item['company_name'] ?? null),
            'location' => $this->text($item['location'] ?? null),
            'start_date' => $this->text($item['start_date'] ?? null),
            'end_date' => $this->text($item['end_date'] ?? null),
            'is_current' => (bool) ($item['is_current'] ?? false),
            'description' => $this->text($item['description'] ?? null),
        ], array_filter($items, 'is_array')));
    }

    /** @return list<array<string, mixed>> */
    private function snapshotEducation(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(fn (array $item): array => [
            'institution' => $this->text($item['institution'] ?? null),
            'degree' => $this->text($item['degree'] ?? null),
            'field_of_study' => $this->text($item['field_of_study'] ?? null),
            'start_date' => $this->text($item['start_date'] ?? null),
            'end_date' => $this->text($item['end_date'] ?? null),
            'description' => $this->text($item['description'] ?? null),
        ], array_filter($items, 'is_array')));
    }

    /** @return list<string> */
    private function snapshotSkills(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $skills = [];
        foreach ($items as $item) {
            $name = is_array($item) ? $this->text($item['name'] ?? null) : $this->text($item);
            if ($name !== null) {
                $skills[] = $name;
            }
        }

        return array_values(array_unique($skills));
    }

    private function location(mixed $location, mixed $nameAr, mixed $nameEn): ?string
    {
        $location = $this->text($location);
        $city = app()->getLocale() === 'ar'
            ? $this->text($nameAr) ?? $this->text($nameEn)
            : $this->text($nameEn) ?? $this->text($nameAr);

        if ($location === null) {
            return $city;
        }
        if ($city === null || mb_strtolower($location) === mb_strtolower($city)) {
            return $location;
        }

        return "{$location}, {$city}";
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
