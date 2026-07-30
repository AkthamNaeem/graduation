<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompanyInvitation;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyInvitation */
class CompanyInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'email' => $this->email,
            'company_role' => LocalizedValue::make($this->company_role, 'company_roles'),
            'status' => LocalizedValue::make($this->status, 'company_invitation_statuses'),
            'expires_at' => $this->expires_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'invited_by_user_id' => $this->invited_by_user_id,
            'accepted_by_user_id' => $this->accepted_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
