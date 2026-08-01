<?php

namespace App\Services;

use App\Data\ProfilePageData;
use App\Enums\ProfileAction;
use App\Enums\UserRole;
use App\Models\JobSeekerProfile;
use App\Models\User;

class ProfilePageService
{
    public function __construct(
        private readonly ProfileExperienceCalculator $experienceCalculator,
    ) {}

    public function get(User $user): ProfilePageData
    {
        $profile = JobSeekerProfile::query()
            ->where('user_id', $user->id)
            ->with([
                'user',
                'city',
                'experiences' => fn ($query) => $query
                    ->orderByDesc('is_current')
                    ->orderByDesc('end_date')
                    ->orderByDesc('start_date')
                    ->orderByDesc('id'),
                'education' => fn ($query) => $query
                    ->orderByDesc('end_date')
                    ->orderByDesc('start_date')
                    ->orderByDesc('id'),
                'skills' => fn ($query) => $query
                    ->orderBy('skills.name')
                    ->orderBy('skills.id'),
            ])
            ->firstOrFail();

        return new ProfilePageData(
            profile: $profile,
            yearsOfExperience: $this->experienceCalculator->years($profile->experiences),
            professionalLinks: $this->professionalLinks($profile),
            allowedActions: $user->role === UserRole::JOB_SEEKER ? ProfileAction::values() : [],
        );
    }

    /** @return list<array{key: string, url: string}> */
    private function professionalLinks(JobSeekerProfile $profile): array
    {
        $links = [
            'github' => $profile->github_url,
            'linkedin' => $profile->linkedin_url,
            'portfolio' => $profile->portfolio_url,
        ];

        $result = [];
        $seenUrls = [];
        foreach ($links as $key => $url) {
            if (! is_string($url) || trim($url) === '' || in_array($url, $seenUrls, true)) {
                continue;
            }

            $result[] = ['key' => $key, 'url' => $url];
            $seenUrls[] = $url;
        }

        return $result;
    }
}
