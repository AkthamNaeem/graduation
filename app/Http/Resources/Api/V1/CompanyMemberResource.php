<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\EmployerProfile;
use App\Services\CompanyPermissionService;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployerProfile */
class CompanyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum') ?? $request->user();
        $permissions = app(CompanyPermissionService::class);
        $canManage = $viewer !== null
            && $permissions->can($viewer, CompanyPermission::MANAGE_TEAM, $this->company_id);
        $targetIsOwner = $this->company_role === CompanyRole::OWNER;

        return [
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'company_role' => LocalizedValue::make($this->company_role, 'company_roles'),
            'membership_status' => LocalizedValue::make($this->membership_status, 'company_membership_statuses'),
            'joined_at' => $this->joined_at?->toISOString(),
            'suspended_at' => $this->suspended_at?->toISOString(),
            'removed_at' => $this->removed_at?->toISOString(),
            'is_current_user' => $viewer?->id === $this->user_id,
            'available_actions' => [
                'change_role' => $canManage && ! $targetIsOwner,
                'suspend' => $canManage
                    && ! $targetIsOwner
                    && $this->membership_status === CompanyMembershipStatus::ACTIVE,
                'reactivate' => $canManage
                    && $this->membership_status === CompanyMembershipStatus::SUSPENDED,
                'remove' => $canManage && ! $targetIsOwner,
            ],
        ];
    }
}
