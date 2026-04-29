<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\JobApplication */
class JobApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_posting_id' => $this->job_posting_id,
            'job_seeker_profile_id' => $this->job_seeker_profile_id,
            'application_status_id' => $this->application_status_id,
            'status' => ApplicationStatusResource::make($this->whenLoaded('applicationStatus')),
            'job_posting' => JobPostingResource::make($this->whenLoaded('jobPosting')),
            'job_seeker_profile' => JobSeekerProfileResource::make($this->whenLoaded('jobSeekerProfile')),
            'status_history' => ApplicationStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
