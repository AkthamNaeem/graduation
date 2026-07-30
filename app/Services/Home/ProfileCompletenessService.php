<?php

namespace App\Services\Home;

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
        'confirmed_primary_cv' => 10,
    ];

    /**
     * @return array<string, mixed>
     */
    public function calculate(User $user, ?JobSeekerProfile $profile = null): array
    {
        $profile ??= $user->jobSeekerProfile()
            ->withCount(['experiences', 'education', 'skills'])
            ->with('primaryCVFile:id,user_id,confirmed_at,archived_at')
            ->first();

        $missing = [];
        $percentage = 0;

        $this->score(
            filled($user->name) && filled($user->email) && filled($profile?->phone),
            'basic_information',
            [
                'key' => 'basic_information',
                'label' => 'أكمل بياناتك الأساسية',
                'target' => ['type' => 'profile_section', 'value' => 'basic_information'],
            ],
            $percentage,
            $missing,
        );

        $professionalComplete = filled($profile?->headline) && filled($profile?->summary);
        if ($professionalComplete) {
            $percentage += self::WEIGHTS['professional_profile'];
        } else {
            if (blank($profile?->headline)) {
                $missing[] = [
                    'key' => 'professional_headline',
                    'label' => 'أضف مسماك المهني',
                    'target' => ['type' => 'profile_section', 'value' => 'professional_headline'],
                ];
            }
            if (blank($profile?->summary)) {
                $missing[] = [
                    'key' => 'professional_summary',
                    'label' => 'أضف ملخصك المهني',
                    'target' => ['type' => 'profile_section', 'value' => 'professional_summary'],
                ];
            }
        }

        $this->score(
            filled($profile?->location),
            'location',
            [
                'key' => 'location',
                'label' => 'أضف موقعك',
                'target' => ['type' => 'profile_section', 'value' => 'location'],
            ],
            $percentage,
            $missing,
        );
        $this->score(
            (int) ($profile?->experiences_count ?? 0) >= 1,
            'experience',
            [
                'key' => 'experience',
                'label' => 'أضف خبرة عملية',
                'target' => ['type' => 'profile_section', 'value' => 'experiences'],
            ],
            $percentage,
            $missing,
        );
        $this->score(
            (int) ($profile?->education_count ?? 0) >= 1,
            'education',
            [
                'key' => 'education',
                'label' => 'أضف مؤهلك التعليمي',
                'target' => ['type' => 'profile_section', 'value' => 'education'],
            ],
            $percentage,
            $missing,
        );
        $this->score(
            (int) ($profile?->skills_count ?? 0) >= 3,
            'skills',
            [
                'key' => 'skills',
                'label' => 'أضف ثلاث مهارات على الأقل',
                'target' => ['type' => 'profile_section', 'value' => 'skills'],
            ],
            $percentage,
            $missing,
        );

        $primaryCV = $profile?->relationLoaded('primaryCVFile')
            ? $profile->primaryCVFile
            : null;
        $this->score(
            $primaryCV !== null
                && $primaryCV->user_id === $user->id
                && $primaryCV->confirmed_at !== null
                && $primaryCV->archived_at === null,
            'confirmed_primary_cv',
            [
                'key' => 'confirmed_primary_cv',
                'label' => 'اختر سيرة ذاتية أساسية وأكّدها',
                'target' => ['type' => 'cv', 'value' => 'primary'],
            ],
            $percentage,
            $missing,
        );

        return [
            'percentage' => $percentage,
            'is_complete' => $percentage === 100,
            'missing_items_count' => count($missing),
            'missing_items' => $missing,
            'next_item' => $missing[0] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $missing
     */
    private function score(
        bool $complete,
        string $weightKey,
        array $item,
        int &$percentage,
        array &$missing,
    ): void {
        if ($complete) {
            $percentage += self::WEIGHTS[$weightKey];

            return;
        }

        $missing[] = $item;
    }
}
