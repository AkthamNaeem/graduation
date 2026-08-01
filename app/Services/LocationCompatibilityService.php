<?php

namespace App\Services;

use App\Enums\JobWorkMode;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;

class LocationCompatibilityService
{
    /** @return array{status:string,score:float,max_score:float,match_percentage:float,message:string,reason_code:string} */
    public function evaluate(JobPosting $job, JobSeekerProfile $profile, float $maxScore): array
    {
        $mode = $job->work_mode instanceof JobWorkMode
            ? $job->work_mode
            : JobWorkMode::tryFrom((string) $job->work_mode);

        [$status, $percentage, $reasonCode] = match (true) {
            $mode === JobWorkMode::REMOTE => ['remote', 100.0, 'REMOTE_LOCATION_COMPATIBLE'],
            $job->city_id === null || $profile->city_id === null => ['missing', 50.0, 'LOCATION_DATA_MISSING'],
            (int) $job->city_id === (int) $profile->city_id => ['same_city', 100.0, 'SAME_CITY'],
            default => ['different_city', 0.0, 'DIFFERENT_CITY'],
        };

        return [
            'status' => $status,
            'score' => round($maxScore * $percentage / 100, 2),
            'max_score' => $maxScore,
            'match_percentage' => $percentage,
            'message' => __('ai.reasons.'.$reasonCode),
            'reason_code' => $reasonCode,
        ];
    }
}
