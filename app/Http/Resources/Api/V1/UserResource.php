<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->value ?? $this->role,
            'status' => $this->status?->value ?? $this->status,
            'job_seeker_profile' => $this->when(
                $this->relationLoaded('jobSeekerProfile') && $this->jobSeekerProfile,
                fn (): JobSeekerProfileResource => new JobSeekerProfileResource($this->jobSeekerProfile),
            ),
            'employer_profile' => $this->when(
                $this->relationLoaded('employerProfile') && $this->employerProfile,
                fn (): EmployerProfileResource => new EmployerProfileResource($this->employerProfile),
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
