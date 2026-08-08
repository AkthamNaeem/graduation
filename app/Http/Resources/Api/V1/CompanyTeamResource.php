<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use App\Support\CompanyMedia;
use App\Support\LocalizedValue;
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
                ...CompanyMedia::urls($this->resource),
                'approval_status' => LocalizedValue::make($this->approval_status, 'company_approval_statuses'),
            ],
            'members' => CompanyMemberResource::collection($this->whenLoaded('employerProfiles')),
        ];
    }
}
