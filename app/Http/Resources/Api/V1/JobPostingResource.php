<?php

namespace App\Http\Resources\Api\V1;

use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobPosting */
class JobPostingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'employment_type' => $this->employment_type,
            'experience_level' => $this->experience_level,
            'location' => $this->location,
            'work_mode' => $this->work_mode?->value ?? $this->work_mode,
            'salary_min' => $this->salary_min,
            'salary_max' => $this->salary_max,
            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'application_deadline' => $this->application_deadline?->toISOString(),
            'has_application_deadline' => $this->hasApplicationDeadline(),
            'is_application_deadline_passed' => $this->isApplicationDeadlinePassed(),
            'can_apply' => $this->acceptsApplications(),
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'skills' => SkillResource::collection($this->whenLoaded('skills')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
