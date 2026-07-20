<?php

namespace App\Http\Resources\Api\V1;

use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendedJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JobPosting $job */
        $job = $this->resource['job'];

        return [
            ...(new JobPostingResource($job))->toArray($request),
            'score' => $this->resource['score'],
            'matching_score_version' => $this->resource['matching_score_version'],
            'breakdown' => $this->resource['breakdown'],
            'matched_skills' => $this->resource['matched_skills'],
            'skill_breakdown' => $this->resource['skill_breakdown'],
            'matched_required_skills' => $this->resource['matched_required_skills'],
            'missing_required_skills' => $this->resource['missing_required_skills'],
            'matched_nice_to_have_skills' => $this->resource['matched_nice_to_have_skills'],
            'reasons' => $this->resource['reasons'],
        ];
    }
}
