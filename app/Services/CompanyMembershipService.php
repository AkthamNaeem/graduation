<?php

namespace App\Services;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Exceptions\CompanyManagementException;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CompanyMembershipService
{
    public function __construct(
        private readonly CompanyPermissionService $permissionService,
        private readonly AuditLogService $auditLogService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, EmployerProfile>
     */
    public function list(User $actor, Company $company, array $filters): LengthAwarePaginator
    {
        $this->permissionService->assertCan($actor, CompanyPermission::VIEW_TEAM, $company);
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $direction = $filters['sort_direction'] ?? 'desc';

        $query = EmployerProfile::query()
            ->select('employer_profiles.*')
            ->join('users', 'users.id', '=', 'employer_profiles.user_id')
            ->with('user')
            ->where('employer_profiles.company_id', $company->id)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%");
                });
            })
            ->when($filters['company_role'] ?? null, fn ($query, string $role) => $query->where('employer_profiles.company_role', $role))
            ->when($filters['membership_status'] ?? null, fn ($query, string $status) => $query->where('employer_profiles.membership_status', $status));

        $sortColumn = in_array($sortBy, ['name', 'email'], true)
            ? "users.{$sortBy}"
            : "employer_profiles.{$sortBy}";

        return $query->orderBy($sortColumn, $direction)
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function updateRole(User $actor, Company $company, User $target, CompanyRole $newRole): EmployerProfile
    {
        $this->permissionService->assertCan($actor, CompanyPermission::MANAGE_TEAM, $company);

        return DB::transaction(function () use ($actor, $company, $target, $newRole): EmployerProfile {
            $profile = $this->lockedMember($company, $target);
            $this->assertCanManageTarget($actor, $profile, $newRole);
            $oldRole = $profile->company_role;
            if ($oldRole === $newRole) {
                return $profile->load('user');
            }

            if ($oldRole === CompanyRole::OWNER && $newRole !== CompanyRole::OWNER) {
                $this->assertAnotherActiveOwnerExists($company, $profile->id);
            }

            $profile->forceFill(['company_role' => $newRole])->save();
            $this->auditLogService->record(
                'company.member.role_changed',
                $actor,
                EmployerProfile::class,
                $profile->id,
                ['company_role' => $oldRole->value],
                ['company_role' => $newRole->value],
                ['company_id' => $company->id, 'user_id' => $target->id],
            );
            $this->notifyMember(
                $target,
                'company.member.role_changed',
                'Company role changed',
                "Your role at {$company->name} is now {$newRole->value}.",
                $company,
            );

            return $profile->refresh()->load('user');
        });
    }

    public function updateStatus(
        User $actor,
        Company $company,
        User $target,
        CompanyMembershipStatus $status,
    ): EmployerProfile {
        $this->permissionService->assertCan($actor, CompanyPermission::MANAGE_TEAM, $company);

        return DB::transaction(function () use ($actor, $company, $target, $status): EmployerProfile {
            $profile = $this->lockedMember($company, $target);
            $this->assertCanManageTarget($actor, $profile);
            if ($profile->membership_status === CompanyMembershipStatus::REMOVED) {
                throw new CompanyManagementException(__('domain_errors.COMPANY_MEMBER_INACTIVE'), 'COMPANY_MEMBER_INACTIVE',
                );
            }
            if ($profile->company_role === CompanyRole::OWNER && $status !== CompanyMembershipStatus::ACTIVE) {
                $this->assertAnotherActiveOwnerExists($company, $profile->id);
            }

            $previous = $profile->membership_status;
            $profile->forceFill([
                'membership_status' => $status,
                'suspended_at' => $status === CompanyMembershipStatus::SUSPENDED ? now() : null,
                'removed_at' => null,
            ])->save();

            if ($status === CompanyMembershipStatus::SUSPENDED) {
                $target->tokens()->delete();
            }

            $action = $status === CompanyMembershipStatus::ACTIVE
                ? 'company.member.reactivated'
                : 'company.member.suspended';
            $this->recordMembershipChange($action, $actor, $company, $target, $profile, $previous, $status);

            return $profile->refresh()->load('user');
        });
    }

    public function remove(User $actor, Company $company, User $target): EmployerProfile
    {
        $this->permissionService->assertCan($actor, CompanyPermission::MANAGE_TEAM, $company);

        return DB::transaction(function () use ($actor, $company, $target): EmployerProfile {
            $profile = $this->lockedMember($company, $target);
            $this->assertCanManageTarget($actor, $profile);
            if ($profile->company_role === CompanyRole::OWNER) {
                $this->assertAnotherActiveOwnerExists($company, $profile->id);
            }

            $previous = $profile->membership_status;
            $profile->forceFill([
                'membership_status' => CompanyMembershipStatus::REMOVED,
                'removed_at' => now(),
                'suspended_at' => null,
            ])->save();
            $target->tokens()->delete();
            $this->recordMembershipChange(
                'company.member.removed',
                $actor,
                $company,
                $target,
                $profile,
                $previous,
                CompanyMembershipStatus::REMOVED,
            );

            return $profile->refresh()->load('user');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function transferOwnership(User $actor, Company $company, array $data): EmployerProfile
    {
        $this->permissionService->assertCan($actor, CompanyPermission::TRANSFER_OWNERSHIP, $company);

        return DB::transaction(function () use ($actor, $company, $data): EmployerProfile {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $target = EmployerProfile::query()
                ->where('company_id', $company->id)
                ->where('user_id', $data['new_owner_user_id'])
                ->lockForUpdate()
                ->first();

            if (! $target instanceof EmployerProfile
                || $target->membership_status !== CompanyMembershipStatus::ACTIVE) {
                throw new CompanyManagementException(__('domain_errors.COMPANY_OWNERSHIP_TARGET_INVALID'), 'COMPANY_OWNERSHIP_TARGET_INVALID',
                );
            }

            $currentOwner = $this->currentOwnerForTransfer($actor, $company, $data);
            if ($currentOwner->id === $target->id) {
                throw new CompanyManagementException(__('domain_errors.COMPANY_OWNERSHIP_TARGET_INVALID'), 'COMPANY_OWNERSHIP_TARGET_INVALID',
                );
            }

            $previousRole = CompanyRole::from(
                $data['previous_owner_role'] ?? CompanyRole::COMPANY_ADMIN->value,
            );
            if ($previousRole === CompanyRole::OWNER) {
                throw new CompanyManagementException(__('domain_errors.COMPANY_OWNERSHIP_TARGET_INVALID'), 'COMPANY_OWNERSHIP_TARGET_INVALID',
                );
            }

            $currentOwner->forceFill(['company_role' => $previousRole])->save();
            $target->forceFill(['company_role' => CompanyRole::OWNER])->save();

            $this->auditLogService->record(
                'company.ownership.transferred',
                $actor,
                Company::class,
                $company->id,
                ['owner_user_id' => $currentOwner->user_id],
                ['owner_user_id' => $target->user_id],
                [
                    'company_id' => $company->id,
                    'old_owner_user_id' => $currentOwner->user_id,
                    'new_owner_user_id' => $target->user_id,
                    'actor_id' => $actor->id,
                ],
            );
            $this->notifyMember(
                $target->user,
                'company.ownership.transferred',
                'Company ownership transferred',
                "You are now the owner of {$company->name}.",
                $company,
            );
            $this->notifyMember(
                $currentOwner->user,
                'company.ownership.transferred',
                'Company ownership transferred',
                "Ownership of {$company->name} was transferred.",
                $company,
            );

            return $target->refresh()->load('user');
        }, 3);
    }

    private function lockedMember(Company $company, User $target): EmployerProfile
    {
        $profile = EmployerProfile::query()
            ->where('company_id', $company->id)
            ->where('user_id', $target->id)
            ->lockForUpdate()
            ->first();

        if (! $profile instanceof EmployerProfile) {
            throw new CompanyManagementException(__('domain_errors.COMPANY_MEMBER_NOT_FOUND'), 'COMPANY_MEMBER_NOT_FOUND',
                404,
            );
        }

        return $profile;
    }

    private function assertCanManageTarget(
        User $actor,
        EmployerProfile $target,
        ?CompanyRole $newRole = null,
    ): void {
        if ($this->permissionService->isAdministrator($actor)) {
            return;
        }

        $actorMembership = $this->permissionService->activeMembership($actor);
        if (! $actorMembership instanceof EmployerProfile) {
            throw new CompanyManagementException(__('domain_errors.COMPANY_MEMBER_INACTIVE'), 'COMPANY_MEMBER_INACTIVE',
                403,
            );
        }

        if ($actorMembership->company_role === CompanyRole::COMPANY_ADMIN
            && ($target->company_role === CompanyRole::OWNER || $newRole === CompanyRole::OWNER)) {
            throw new CompanyManagementException(__('domain_errors.COMPANY_MEMBER_ROLE_FORBIDDEN'), 'COMPANY_MEMBER_ROLE_FORBIDDEN',
                403,
            );
        }

        if ($actorMembership->company_role === CompanyRole::OWNER
            && $target->company_role === CompanyRole::OWNER
            && $actorMembership->id !== $target->id) {
            throw new CompanyManagementException(__('domain_errors.COMPANY_OWNER_TRANSFER_REQUIRED'), 'COMPANY_OWNER_TRANSFER_REQUIRED',
                409,
            );
        }

        if ($newRole !== null && ! $actorMembership->company_role->canAssign($newRole)) {
            throw new CompanyManagementException(__('domain_errors.COMPANY_MEMBER_ROLE_FORBIDDEN'), 'COMPANY_MEMBER_ROLE_FORBIDDEN',
                403,
            );
        }
    }

    private function assertAnotherActiveOwnerExists(Company $company, int $excludedProfileId): void
    {
        $exists = EmployerProfile::query()
            ->where('company_id', $company->id)
            ->whereKeyNot($excludedProfileId)
            ->where('company_role', CompanyRole::OWNER)
            ->where('membership_status', CompanyMembershipStatus::ACTIVE)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            throw new CompanyManagementException(__('domain_errors.COMPANY_LAST_OWNER_REQUIRED'), 'COMPANY_LAST_OWNER_REQUIRED',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function currentOwnerForTransfer(User $actor, Company $company, array $data): EmployerProfile
    {
        $query = EmployerProfile::query()
            ->where('company_id', $company->id)
            ->where('company_role', CompanyRole::OWNER)
            ->where('membership_status', CompanyMembershipStatus::ACTIVE);

        if ($this->permissionService->isAdministrator($actor)) {
            if (isset($data['current_owner_user_id'])) {
                $query->where('user_id', $data['current_owner_user_id']);
            }
        } else {
            $query->where('user_id', $actor->id);
        }

        $owner = $query->orderBy('id')->lockForUpdate()->first();
        if (! $owner instanceof EmployerProfile) {
            throw new CompanyManagementException(__('domain_errors.COMPANY_LAST_OWNER_REQUIRED'), 'COMPANY_LAST_OWNER_REQUIRED',
            );
        }

        return $owner;
    }

    private function recordMembershipChange(
        string $action,
        User $actor,
        Company $company,
        User $target,
        EmployerProfile $profile,
        CompanyMembershipStatus $before,
        CompanyMembershipStatus $after,
    ): void {
        $this->auditLogService->record(
            $action,
            $actor,
            EmployerProfile::class,
            $profile->id,
            ['membership_status' => $before->value],
            ['membership_status' => $after->value],
            ['company_id' => $company->id, 'user_id' => $target->id],
        );
        $this->notifyMember(
            $target,
            $action,
            'Company membership updated',
            "Your membership at {$company->name} is now {$after->value}.",
            $company,
        );
    }

    private function notifyMember(
        ?User $user,
        string $type,
        string $title,
        string $message,
        Company $company,
    ): void {
        if ($user instanceof User) {
            $this->notificationService->createForUser(
                $user,
                $type,
                $title,
                $message,
                ['company_id' => $company->id],
            );
        }
    }
}
