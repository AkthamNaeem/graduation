<?php

namespace App\Services\CV;

use App\Models\JobSeekerProfile;

class ProfileDataStateService
{
    public function hasMeaningfulData(JobSeekerProfile $profile): bool
    {
        foreach (['headline', 'summary', 'phone', 'location', 'portfolio_url', 'linkedin_url', 'github_url'] as $field) {
            if (is_string($profile->{$field}) && trim($profile->{$field}) !== '') {
                return true;
            }
        }

        $profile->loadMissing(['experiences:id,job_seeker_profile_id', 'education:id,job_seeker_profile_id', 'skills:id']);

        return $profile->experiences->isNotEmpty()
            || $profile->education->isNotEmpty()
            || $profile->skills->isNotEmpty();
    }
}
