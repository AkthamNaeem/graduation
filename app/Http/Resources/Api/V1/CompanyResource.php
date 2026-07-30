<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'industry' => $this->industry,
            'website' => $this->website,
            'location' => $this->location,
            'description' => $this->description,
            'approval_status' => LocalizedValue::make($this->approval_status, 'company_approval_statuses'),
            'has_owner' => $this->when(
                array_key_exists('owner_count', $this->resource->getAttributes()),
                fn (): bool => (int) $this->owner_count > 0,
            ),
            'setup_complete' => $this->when(
                array_key_exists('owner_count', $this->resource->getAttributes()),
                fn (): bool => (int) $this->owner_count > 0,
            ),
            'employer_profiles' => EmployerProfileResource::collection($this->whenLoaded('employerProfiles')),
            'counts' => $this->when(
                array_key_exists('employer_profiles_count', $this->resource->getAttributes())
                || array_key_exists('job_postings_count', $this->resource->getAttributes())
                || array_key_exists('applications_count', $this->resource->getAttributes()),
                fn (): array => [
                    'employer_users' => (int) ($this->employer_profiles_count ?? 0),
                    'jobs' => (int) ($this->job_postings_count ?? 0),
                    'applications' => (int) ($this->applications_count ?? 0),
                ],
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
