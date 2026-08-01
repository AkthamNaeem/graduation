<?php

namespace App\Services\Home;

use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Models\User;

class ProfileCompletenessService
{
    private const WEIGHTS = [
        'basic_information' => 15,
        'professional_profile' => 15,
        'location' => 10,
        'experience' => 20,
        'education' => 15,
        'skills' => 15,
        'confirmed_cv' => 10,
    ];

    /**
     * Preserve the compact Home contract while sharing the same evaluation.
     *
     * @return array<string, mixed>
     */
    public function calculate(User $user, ?JobSeekerProfile $profile = null): array
    {
        $evaluation = $this->evaluate($user, $this->profile($user, $profile));
        $missing = array_map(
            fn (string $key): array => $this->homeItem($key),
            $evaluation['missing'],
        );

        return [
            'percentage' => $evaluation['percentage'],
            'is_complete' => $evaluation['percentage'] === 100,
            'missing_items_count' => count($missing),
            'missing_items' => $missing,
            'next_item' => $missing[0] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateForProfilePage(User $user, JobSeekerProfile $profile): array
    {
        $evaluation = $this->evaluate($user, $profile);
        $completed = array_map(
            fn (string $key): array => $this->profileItem($key),
            $evaluation['completed'],
        );
        $missing = array_map(
            fn (string $key): array => [
                ...$this->profileItem($key),
                'required' => true,
            ],
            $evaluation['missing'],
        );
        $recommended = $this->recommendedLinks($profile);

        return [
            'percentage' => $evaluation['percentage'],
            'is_complete' => $evaluation['percentage'] === 100,
            'completed_items_count' => count($completed),
            'missing_items_count' => count($missing),
            'completed_items' => $completed,
            'missing_items' => $missing,
            'recommended_items' => $recommended,
            'next_item' => isset($evaluation['missing'][0])
                ? $this->profileItem($evaluation['missing'][0])
                : null,
        ];
    }

    /**
     * @return array{percentage: int, completed: list<string>, missing: list<string>}
     */
    private function evaluate(User $user, JobSeekerProfile $profile): array
    {
        $completed = [];
        $missing = [];
        $percentage = 0;

        $this->evaluateItem(
            filled($user->name) && filled($user->email) && filled($profile->phone),
            'basic_information',
            $percentage,
            $completed,
            $missing,
        );

        $professionalComplete = filled($profile->headline) && filled($profile->summary);
        if ($professionalComplete) {
            $percentage += self::WEIGHTS['professional_profile'];
            $completed[] = 'professional_profile';
        } else {
            if (blank($profile->headline)) {
                $missing[] = 'professional_headline';
            }
            if (blank($profile->summary)) {
                $missing[] = 'professional_summary';
            }
        }

        $this->evaluateItem(
            filled($profile->location) || $profile->city_id !== null,
            'location',
            $percentage,
            $completed,
            $missing,
        );
        $this->evaluateItem(
            $this->relationCount($profile, 'experiences', 'experiences_count') >= 1,
            'experience',
            $percentage,
            $completed,
            $missing,
        );
        $this->evaluateItem(
            $this->relationCount($profile, 'education', 'education_count') >= 1,
            'education',
            $percentage,
            $completed,
            $missing,
        );
        $this->evaluateItem(
            $this->relationCount($profile, 'skills', 'skills_count') >= 3,
            'skills',
            $percentage,
            $completed,
            $missing,
        );
        $this->evaluateItem(
            $this->hasConfirmedCV($user, $profile),
            'confirmed_cv',
            $percentage,
            $completed,
            $missing,
        );

        return compact('percentage', 'completed', 'missing');
    }

    /**
     * @param  list<string>  $completed
     * @param  list<string>  $missing
     */
    private function evaluateItem(
        bool $complete,
        string $key,
        int &$percentage,
        array &$completed,
        array &$missing,
    ): void {
        if ($complete) {
            $percentage += self::WEIGHTS[$key];
            $completed[] = $key;

            return;
        }

        $missing[] = $key;
    }

    private function profile(User $user, ?JobSeekerProfile $profile): JobSeekerProfile
    {
        if ($profile instanceof JobSeekerProfile) {
            return $profile;
        }

        return $user->jobSeekerProfile()
            ->withCount(['experiences', 'education', 'skills'])
            ->with('latestConfirmedCVFile')
            ->firstOrFail();
    }

    private function relationCount(JobSeekerProfile $profile, string $relation, string $attribute): int
    {
        if ($profile->relationLoaded($relation)) {
            return $profile->{$relation}->count();
        }

        return (int) ($profile->{$attribute} ?? 0);
    }

    private function hasConfirmedCV(User $user, JobSeekerProfile $profile): bool
    {
        if ($profile->relationLoaded('latestConfirmedCVFile')) {
            $cv = $profile->latestConfirmedCVFile;

            return $cv instanceof CVFile
                && $cv->user_id === $user->id
                && $cv->confirmed_at !== null
                && $cv->status === 'parsed'
                && $cv->isUsableForApplication();
        }

        // Compatibility for callers that still provide the legacy, partially-selected relation.
        $cv = $profile->relationLoaded('primaryCVFile') ? $profile->primaryCVFile : null;

        return $cv instanceof CVFile
            && $cv->user_id === $user->id
            && $cv->confirmed_at !== null
            && $cv->archived_at === null;
    }

    /** @return array<string, mixed> */
    private function homeItem(string $key): array
    {
        $homeKey = $key === 'confirmed_cv' ? 'confirmed_primary_cv' : $key;
        $translation = match ($key) {
            'basic_information' => 'basic',
            'professional_headline' => 'headline',
            'professional_summary' => 'summary',
            'confirmed_cv' => 'cv',
            default => $key,
        };

        return [
            'key' => $homeKey,
            'label' => __("home.completeness.{$translation}"),
            'target' => $key === 'confirmed_cv'
                ? ['type' => 'cv', 'value' => 'primary']
                : ['type' => 'profile_section', 'value' => $this->targetValue($key)],
        ];
    }

    /** @return array<string, mixed> */
    private function profileItem(string $key): array
    {
        return [
            'key' => $key,
            'label' => __("profile.completeness.items.{$key}"),
            'target' => $key === 'confirmed_cv'
                ? ['type' => 'cv', 'value' => 'confirmed']
                : ['type' => 'profile_section', 'value' => $this->targetValue($key)],
        ];
    }

    private function targetValue(string $key): string
    {
        return match ($key) {
            'experience' => 'experiences',
            'professional_profile' => 'professional_summary',
            default => $key,
        };
    }

    /** @return list<array<string, mixed>> */
    private function recommendedLinks(JobSeekerProfile $profile): array
    {
        $recommended = [];
        foreach ([
            'github_link' => $profile->github_url,
            'linkedin_link' => $profile->linkedin_url,
            'portfolio_link' => $profile->portfolio_url,
        ] as $key => $url) {
            if (filled($url)) {
                continue;
            }

            $recommended[] = [
                'key' => $key,
                'label' => __("profile.completeness.items.{$key}"),
                'target' => ['type' => 'profile_section', 'value' => 'professional_links'],
                'required' => false,
            ];
        }

        return $recommended;
    }
}
