<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'role' => LocalizedValue::make($this->role, 'user_roles'),
            'status' => LocalizedValue::make($this->status, 'user_statuses'),
            'avatar_url' => $this->avatar_path === null ? null : Storage::disk('public')->url($this->avatar_path),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'is_email_verified' => $this->email_verified_at !== null,
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
