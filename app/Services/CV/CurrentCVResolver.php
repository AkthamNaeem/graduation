<?php

namespace App\Services\CV;

use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Models\User;

class CurrentCVResolver
{
    public function resolve(User $user, JobSeekerProfile $profile): ?CVFile
    {
        $cv = $profile->relationLoaded('primaryCVFile')
            ? $profile->primaryCVFile
            : null;

        if (! $cv instanceof CVFile
            || $cv->user_id !== $user->id
            || ! $cv->isConfirmedUsableForApplication()) {
            return null;
        }

        return $cv;
    }
}
