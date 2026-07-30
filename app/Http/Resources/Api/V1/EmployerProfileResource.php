<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EmployerProfile;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployerProfile */
class EmployerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'company_role' => LocalizedValue::make($this->company_role, 'company_roles'),
            'membership_status' => LocalizedValue::make($this->membership_status, 'company_membership_statuses'),
            'joined_at' => $this->joined_at?->toISOString(),
            'suspended_at' => $this->suspended_at?->toISOString(),
            'removed_at' => $this->removed_at?->toISOString(),
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'user' => UserResource::make($this->whenLoaded('user')),
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
