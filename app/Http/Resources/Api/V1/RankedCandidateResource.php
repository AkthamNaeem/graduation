<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApplicationStatus;
use App\Models\JobSeekerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RankedCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApplicationStatus $applicationStatus */
        $applicationStatus = $this->resource['application_status'];
        /** @var JobSeekerProfile $jobSeekerProfile */
        $jobSeekerProfile = $this->resource['job_seeker_profile'];

        return [
            'job_application_id' => $this->resource['job_application_id'],
            'application_status' => new ApplicationStatusResource($applicationStatus),
            'score' => $this->resource['score'],
            'breakdown' => $this->resource['breakdown'],
            'matched_skills' => $this->resource['matched_skills'],
            'skill_breakdown' => $this->resource['skill_breakdown'],
            'job_seeker_profile' => new JobSeekerProfileResource($jobSeekerProfile),
        ];
    }
}
