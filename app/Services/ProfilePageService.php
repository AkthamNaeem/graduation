<?php

namespace App\Services;

use App\Data\ProfilePageData;
use App\Enums\ProfileAction;
use App\Enums\UserRole;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use App\Models\User;
use App\Services\CV\CandidateCVStateResolver;
use App\Services\Home\ProfileCompletenessService;

class ProfilePageService
{
    public function __construct(
        private readonly ProfileExperienceCalculator $experienceCalculator,
        private readonly ProfileCompletenessService $profileCompletenessService,
        private readonly ProfileAttentionResolver $profileAttentionResolver,
        private readonly CandidateCVStateResolver $candidateCVStateResolver,
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
                'primaryCVFile' => fn ($query) => $query->select([
                    'id',
                    'user_id',
                    'original_name',
                    'stored_path',
                    'disk',
                    'mime_type',
                    'extension',
                    'size_bytes',
                    'status',
                    'confirmed_at',
                    'archived_at',
                    'cancelled_at',
                    'created_at',
                    'updated_at',
                ]),
                'latestUnconfirmedCVFile' => fn ($query) => $query
                    ->select([
                        'cv_files.id',
                        'cv_files.user_id',
                        'cv_files.original_name',
                        'cv_files.stored_path',
                        'cv_files.disk',
                        'cv_files.mime_type',
                        'cv_files.extension',
                        'cv_files.size_bytes',
                        'cv_files.status',
                        'cv_files.review_mode',
                        'cv_files.review_status',
                        'cv_files.confirmed_at',
                        'cv_files.archived_at',
                        'cv_files.cancelled_at',
                        'cv_files.created_at',
                        'cv_files.updated_at',
                    ])
                    ->with(['parsingResult' => fn ($parsingResult) => $parsingResult->select([
                        'id',
                        'cv_file_id',
                        'reviewed_at',
                        'created_at',
                    ])])
                    ->withCount(['profileChangeSuggestions as pending_suggestions_count' => fn ($suggestions) => $suggestions
                        ->where('status', ProfileChangeSuggestion::STATUS_PENDING)
                        ->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE)]),
            ])
            ->firstOrFail();

        $cvState = $this->candidateCVStateResolver->resolve($user, $profile);
        $profileCompleteness = $this->profileCompletenessService
            ->calculateForProfilePage($user, $profile);

        return new ProfilePageData(
            profile: $profile,
            yearsOfExperience: $this->experienceCalculator->years($profile->experiences),
            professionalLinks: $this->professionalLinks($profile),
            allowedActions: $user->role === UserRole::JOB_SEEKER ? ProfileAction::values() : [],
            profileCompleteness: $profileCompleteness,
            attentionItems: $this->profileAttentionResolver->resolve($profile, $profileCompleteness),
            currentCV: $cvState['current_cv'],
            pendingCVUpdate: $cvState['pending_cv_update'],
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
