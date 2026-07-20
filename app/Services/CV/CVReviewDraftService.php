<?php

namespace App\Services\CV;

use App\Models\CVFile;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use Illuminate\Support\Str;

class CVReviewDraftService
{
    private const SOURCE_CV_CONFIRMED = 'cv_confirmed';

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    public function build(array $parsed): array
    {
        return $this->normalize([
            'profile' => [
                'phone' => $parsed['phone'] ?? null,
                'summary' => $parsed['summary'] ?? null,
                'location' => $parsed['location'] ?? null,
            ],
            'experience' => collect($parsed['experience'] ?? [])->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item): array {
                return [
                    'title' => $item['title'] ?? null,
                    'company_name' => $item['company_name'] ?? null,
                    'location' => $item['location'] ?? null,
                    'start_date' => $item['start_date'] ?? null,
                    'end_date' => $item['end_date'] ?? null,
                    'is_current' => (bool) ($item['is_current'] ?? false),
                    'description' => $item['description'] ?? $this->responsibilitiesDescription($item['responsibilities'] ?? null),
                ];
            })->all(),
            'education' => collect($parsed['education'] ?? [])->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): array => [
                'institution' => $item['institution'] ?? null,
                'degree' => $item['degree'] ?? null,
                'field_of_study' => $item['field_of_study'] ?? null,
                'start_date' => $item['start_date'] ?? $this->yearDate($item['start_year'] ?? null),
                'end_date' => $item['end_date'] ?? $this->yearDate($item['graduation_year'] ?? null),
                'description' => $item['description'] ?? null,
            ])->all(),
            'skills' => $parsed['skills'] ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function normalize(array $draft): array
    {
        $profile = is_array($draft['profile'] ?? null) ? $draft['profile'] : [];

        return [
            'profile' => [
                'phone' => $this->clean($profile['phone'] ?? null),
                'summary' => $this->clean($profile['summary'] ?? null),
                'location' => $this->clean($profile['location'] ?? null),
            ],
            'experience' => collect($draft['experience'] ?? [])->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): array => [
                'title' => $this->clean($item['title'] ?? null),
                'company_name' => $this->clean($item['company_name'] ?? null),
                'location' => $this->clean($item['location'] ?? null),
                'start_date' => $this->clean($item['start_date'] ?? null),
                'end_date' => ($item['is_current'] ?? false) ? null : $this->clean($item['end_date'] ?? null),
                'is_current' => (bool) ($item['is_current'] ?? false),
                'description' => $this->clean($item['description'] ?? null),
            ])->values()->all(),
            'education' => collect($draft['education'] ?? [])->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): array => [
                'institution' => $this->clean($item['institution'] ?? null),
                'degree' => $this->clean($item['degree'] ?? null),
                'field_of_study' => $this->clean($item['field_of_study'] ?? null),
                'start_date' => $this->clean($item['start_date'] ?? null),
                'end_date' => $this->clean($item['end_date'] ?? null),
                'description' => $this->clean($item['description'] ?? null),
            ])->values()->all(),
            'skills' => collect($draft['skills'] ?? [])
                ->filter(fn (mixed $skill): bool => is_string($skill))
                ->map(fn (string $skill): string => trim($skill))
                ->filter()
                ->unique(fn (string $skill): string => mb_strtolower($skill))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function apply(JobSeekerProfile $profile, CVFile $cvFile, array $draft): void
    {
        $draft = $this->normalize($draft);
        $profile->forceFill($draft['profile'])->save();
        $source = ['source_type' => self::SOURCE_CV_CONFIRMED, 'source_cv_file_id' => $cvFile->id, 'user_verified_at' => now()];

        foreach ($draft['experience'] as $value) {
            $exists = $profile->experiences()->get()->contains(fn (Experience $item): bool => $this->experienceKey($item->toArray()) === $this->experienceKey($value));
            if (! $exists) {
                $profile->experiences()->create(array_merge($value, $source));
            }
        }

        foreach ($draft['education'] as $value) {
            $exists = $profile->education()->get()->contains(fn (Education $item): bool => $this->educationKey($item->toArray()) === $this->educationKey($value));
            if (! $exists) {
                $profile->education()->create(array_merge($value, $source));
            }
        }

        foreach ($draft['skills'] as $name) {
            $slug = Str::slug($name);
            if ($slug === '') {
                continue;
            }
            $skill = Skill::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
            $profile->skills()->syncWithoutDetaching([$skill->id => $source]);
        }
    }

    private function experienceKey(array $value): string
    {
        return $this->key([$value['title'] ?? null, $value['company_name'] ?? null, $this->date($value['start_date'] ?? null)]);
    }

    private function educationKey(array $value): string
    {
        return $this->key([$value['institution'] ?? null, $value['degree'] ?? null, $this->date($value['start_date'] ?? null)]);
    }

    private function key(array $parts): string
    {
        return collect($parts)->map(fn (mixed $part): string => Str::of((string) $part)->lower()->squish()->toString())->implode('|');
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = $this->clean($value);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : $value;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function yearDate(mixed $year): ?string
    {
        return is_int($year) && $year >= 1900 && $year <= 2200 ? $year.'-01-01' : null;
    }

    private function responsibilitiesDescription(mixed $responsibilities): ?string
    {
        if (! is_array($responsibilities)) {
            return null;
        }

        $lines = collect($responsibilities)->filter(fn (mixed $line): bool => is_string($line))->map(fn (string $line): string => trim($line))->filter()->map(fn (string $line): string => '- '.$line)->all();

        return $lines === [] ? null : implode(PHP_EOL, $lines);
    }
}
