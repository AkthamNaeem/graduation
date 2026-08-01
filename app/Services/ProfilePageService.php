<?php

namespace App\Services;

use App\Data\ProfilePageData;
use App\Enums\ProfileAction;
use App\Enums\UserRole;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use App\Services\Home\ProfileCompletenessService;

class ProfilePageService
{
    public function __construct(
        private readonly ProfileExperienceCalculator $experienceCalculator,
        private readonly ProfileCompletenessService $profileCompletenessService,
        private readonly ProfileAttentionResolver $profileAttentionResolver,
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
                'latestConfirmedCVFile' => fn ($query) => $query->select([
                    'cv_files.id',
                    'cv_files.user_id',
                    'cv_files.stored_path',
                    'cv_files.disk',
                    'cv_files.status',
                    'cv_files.confirmed_at',
                    'cv_files.archived_at',
                ]),
                'latestUnconfirmedCVFile' => fn ($query) => $query
                    ->select([
                        'cv_files.id',
                        'cv_files.user_id',
                        'cv_files.status',
                        'cv_files.review_mode',
                        'cv_files.review_status',
                        'cv_files.confirmed_at',
                        'cv_files.archived_at',
                        'cv_files.updated_at',
                    ])
                    ->withCount(['profileChangeSuggestions as pending_suggestions_count' => fn ($suggestions) => $suggestions
                        ->where('status', ProfileChangeSuggestion::STATUS_PENDING)
                        ->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE)]),
            ])
            ->firstOrFail();

        $profileCompleteness = $this->profileCompletenessService
            ->calculateForProfilePage($user, $profile);

        return new ProfilePageData(
            profile: $profile,
            yearsOfExperience: $this->experienceCalculator->years($profile->experiences),
            professionalLinks: $this->professionalLinks($profile),
            allowedActions: $user->role === UserRole::JOB_SEEKER ? ProfileAction::values() : [],
            profileCompleteness: $profileCompleteness,
            attentionItems: $this->profileAttentionResolver->resolve($profile, $profileCompleteness),
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
