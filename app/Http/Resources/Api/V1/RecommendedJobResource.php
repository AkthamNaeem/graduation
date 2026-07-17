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
            'breakdown' => $this->resource['breakdown'],
            'matched_skills' => $this->resource['matched_skills'],
            'skill_breakdown' => $this->resource['skill_breakdown'],
        ];
    }
}
