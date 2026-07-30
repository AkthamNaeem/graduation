<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'company' => [
                'id' => $this->id,
                'name' => $this->name,
                'approval_status' => $this->approval_status,
            ],
            'members' => CompanyMemberResource::collection($this->whenLoaded('employerProfiles')),
        ];
    }
}
