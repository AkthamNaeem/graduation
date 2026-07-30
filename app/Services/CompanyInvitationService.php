<?php

namespace App\Services;

use App\Enums\CompanyInvitationStatus;
use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\CompanyManagementException;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyInvitationService
{
    public function __construct(
        private readonly CompanyPermissionService $permissionService,
        private readonly AuditLogService $auditLogService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array{email: string, company_role: string}  $data
     * @return array{invitation: CompanyInvitation, token: string}
     */
    public function invite(User $actor, Company $company, array $data): array
    {
        $this->permissionService->assertCan($actor, CompanyPermission::MANAGE_TEAM, $company);
        $role = CompanyRole::from($data['company_role']);
        $this->assertRoleAssignable($actor, $role);
        $email = $this->normalizeEmail($data['email']);

        return DB::transaction(function () use ($actor, $company, $email, $role): array {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $this->assertInvitationTargetAvailable($company, $email);

            $duplicate = CompanyInvitation::query()
                ->where('company_id', $company->id)
                ->where('email', $email)
                ->where('status', CompanyInvitationStatus::PENDING)
                ->lockForUpdate()
                ->first();

            if ($duplicate instanceof CompanyInvitation && $duplicate->expires_at->isFuture()) {
                throw new CompanyManagementException(
                    'A pending invitation already exists for this company and email.',
                    'COMPANY_INVITATION_DUPLICATE_PENDING',
                );
            }

            if ($duplicate instanceof CompanyInvitation) {
                $duplicate->forceFill(['status' => CompanyInvitationStatus::EXPIRED])->save();
            }

            $token = $this->newToken();
            $invitation = CompanyInvitation::query()->create([
                'company_id' => $company->id,
                'email' => $email,
                'company_role' => $role,
                'token_hash' => $this->hashToken($token),
                'status' => CompanyInvitationStatus::PENDING,
                'invited_by_user_id' => $actor->id,
                'expires_at' => now()->addHours(max(1, (int) config('company.invitation_expiration_hours', 72))),
            ]);

            $this->auditLogService->record(
                'company.member.invited',
                $actor,
                CompanyInvitation::class,
                $invitation->id,
                null,
                [
                    'company_id' => $company->id,
                    'email' => $email,
                    'company_role' => $role->value,
                    'status' => CompanyInvitationStatus::PENDING->value,
                ],
                ['company_id' => $company->id, 'invitation_id' => $invitation->id],
            );
            $this->notifyExistingUser(
                $email,
                'company.member.invited',
                'Company invitation',
                "You were invited to join {$company->name}.",
                $company,
            );

            return ['invitation' => $invitation->refresh(), 'token' => $token];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CompanyInvitation>
     */
    public function list(User $actor, Company $company, array $filters): LengthAwarePaginator
    {
        $this->permissionService->assertCan($actor, CompanyPermission::VIEW_TEAM, $company);
        $this->expirePendingInvitations($company);

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        return CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where('email', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['company_role'] ?? null, fn ($query, string $role) => $query->where('company_role', $role))
            ->when($filters['expires_before'] ?? null, fn ($query, string $date) => $query->where('expires_at', '<=', $date))
            ->when($filters['expires_after'] ?? null, fn ($query, string $date) => $query->where('expires_at', '>=', $date))
            ->orderBy($sortBy, $sortDirection)
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @return array{invitation: CompanyInvitation, token: string}
     */
    public function resend(User $actor, CompanyInvitation $target): array
    {
        $company = $target->company()->firstOrFail();
        $this->permissionService->assertCan($actor, CompanyPermission::MANAGE_TEAM, $company);

        return DB::transaction(function () use ($actor, $target, $company): array {
            $invitation = CompanyInvitation::query()->lockForUpdate()->findOrFail($target->id);
            $this->assertPending($invitation);
            $this->assertInvitationTargetAvailable($company, $invitation->email);

            $token = $this->newToken();
            $invitation->forceFill([
                'token_hash' => $this->hashToken($token),
                'expires_at' => now()->addHours(max(1, (int) config('company.invitation_expiration_hours', 72))),
                'status' => CompanyInvitationStatus::PENDING,
            ])->save();

            $this->auditLogService->record(
                'company.invitation.resent',
                $actor,
                CompanyInvitation::class,
                $invitation->id,
                null,
                ['expires_at' => $invitation->expires_at?->toISOString()],
                ['company_id' => $company->id, 'invitation_id' => $invitation->id],
            );

            return ['invitation' => $invitation->refresh(), 'token' => $token];
        });
    }

    public function revoke(User $actor, CompanyInvitation $target): CompanyInvitation
    {
        $company = $target->company()->firstOrFail();
        $this->permissionService->assertCan($actor, CompanyPermission::MANAGE_TEAM, $company);

        return DB::transaction(function () use ($actor, $target, $company): CompanyInvitation {
            $invitation = CompanyInvitation::query()->lockForUpdate()->findOrFail($target->id);
            $this->assertPending($invitation);
            $invitation->forceFill([
                'status' => CompanyInvitationStatus::REVOKED,
                'revoked_at' => now(),
            ])->save();

            $this->auditLogService->record(
                'company.invitation.revoked',
                $actor,
                CompanyInvitation::class,
                $invitation->id,
                ['status' => CompanyInvitationStatus::PENDING->value],
                ['status' => CompanyInvitationStatus::REVOKED->value],
                ['company_id' => $company->id, 'invitation_id' => $invitation->id],
            );
            $this->notifyExistingUser(
                $invitation->email,
                'company.invitation.revoked',
                'Company invitation revoked',
                "Your invitation to {$company->name} was revoked.",
                $company,
            );

            return $invitation->refresh();
        });
    }

    public function inspect(string $token): CompanyInvitation
    {
        $invitation = $this->findByToken($token);
        $this->markExpiredIfNeeded($invitation);
        $this->assertPending($invitation);

        return $invitation->load('company');
    }

    public function reject(string $token): CompanyInvitation
    {
        return DB::transaction(function () use ($token): CompanyInvitation {
            $invitation = $this->lockedByToken($token);
            $this->assertPending($invitation);
            $invitation->forceFill([
                'status' => CompanyInvitationStatus::REJECTED,
                'rejected_at' => now(),
            ])->save();

            $this->auditLogService->record(
                'company.invitation.rejected',
                null,
                CompanyInvitation::class,
                $invitation->id,
                ['status' => CompanyInvitationStatus::PENDING->value],
                ['status' => CompanyInvitationStatus::REJECTED->value],
                ['company_id' => $invitation->company_id, 'invitation_id' => $invitation->id],
            );

            return $invitation->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function accept(string $token, array $data): EmployerProfile
    {
        $expired = false;
        $result = DB::transaction(function () use ($token, $data, &$expired): ?EmployerProfile {
            $invitation = $this->lockedByToken($token);
            if ($invitation->status === CompanyInvitationStatus::PENDING && $invitation->expires_at->isPast()) {
                $invitation->forceFill(['status' => CompanyInvitationStatus::EXPIRED])->save();
                $expired = true;

                return null;
            }
            $this->assertPending($invitation);

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$invitation->email])
                ->lockForUpdate()
                ->first();

            if (! $user instanceof User) {
                if (blank($data['name'] ?? null) || blank($data['password'] ?? null)) {
                    throw ValidationException::withMessages([
                        'name' => ['The name field is required for a new account.'],
                        'password' => ['The password field is required for a new account.'],
                    ]);
                }

                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'role' => UserRole::EMPLOYER,
                    'status' => UserStatus::ACTIVE,
                    'password' => $data['password'],
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif ($user->role !== UserRole::EMPLOYER) {
                throw new CompanyManagementException(
                    'The invitation email belongs to an incompatible account role.',
                    'COMPANY_INVITATION_USER_ROLE_CONFLICT',
                );
            }

            $profile = EmployerProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($profile instanceof EmployerProfile) {
                if ((int) $profile->company_id !== (int) $invitation->company_id) {
                    throw new CompanyManagementException(
                        'This employer already belongs to another company.',
                        'COMPANY_MEMBER_DIFFERENT_COMPANY',
                    );
                }

                if ($profile->membership_status !== CompanyMembershipStatus::REMOVED) {
                    throw new CompanyManagementException(
                        'This user is already a company member.',
                        'COMPANY_MEMBER_ALREADY_EXISTS',
                    );
                }

                $profile->forceFill([
                    'company_role' => $invitation->company_role,
                    'membership_status' => CompanyMembershipStatus::ACTIVE,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'joined_at' => now(),
                    'suspended_at' => null,
                    'removed_at' => null,
                ])->save();
            } else {
                $profile = EmployerProfile::query()->create([
                    'user_id' => $user->id,
                    'company_id' => $invitation->company_id,
                    'company_role' => $invitation->company_role,
                    'membership_status' => CompanyMembershipStatus::ACTIVE,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'joined_at' => now(),
                ]);
            }

            $invitation->forceFill([
                'status' => CompanyInvitationStatus::ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ])->save();
            if (CompanyRole::from((string) $invitation->getRawOriginal('company_role')) === CompanyRole::OWNER) {
                Company::query()
                    ->whereKey($invitation->company_id)
                    ->update(['owner_setup_required' => false]);
            }

            $this->auditLogService->record(
                'company.invitation.accepted',
                $user,
                CompanyInvitation::class,
                $invitation->id,
                ['status' => CompanyInvitationStatus::PENDING->value],
                ['status' => CompanyInvitationStatus::ACCEPTED->value],
                [
                    'company_id' => $invitation->company_id,
                    'invitation_id' => $invitation->id,
                    'accepted_by_user_id' => $user->id,
                ],
            );

            if ($invitation->invitedBy instanceof User) {
                $this->notificationService->createForUser(
                    $invitation->invitedBy,
                    'company.invitation.accepted',
                    'Company invitation accepted',
                    "{$user->name} accepted the company invitation.",
                    ['company_id' => $invitation->company_id, 'user_id' => $user->id],
                );
            }

            return $profile->refresh()->load(['user', 'company']);
        });

        if ($expired || ! $result instanceof EmployerProfile) {
            throw new CompanyManagementException(
                'This company invitation has expired.',
                'COMPANY_INVITATION_EXPIRED',
            );
        }

        return $result;
    }

    private function assertRoleAssignable(User $actor, CompanyRole $role): void
    {
        if ($this->permissionService->isAdministrator($actor)) {
            return;
        }

        $actorRole = $this->permissionService->activeMembership($actor)?->company_role;
        if (! $actorRole?->canAssign($role)) {
            throw new CompanyManagementException(
                'Your company role cannot assign the requested role.',
                'COMPANY_INVITATION_ROLE_FORBIDDEN',
                403,
            );
        }
    }

    private function assertInvitationTargetAvailable(Company $company, string $email): void
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user instanceof User) {
            return;
        }

        if ($user->role !== UserRole::EMPLOYER) {
            throw new CompanyManagementException(
                'The invitation email belongs to an incompatible account role.',
                'COMPANY_INVITATION_USER_ROLE_CONFLICT',
            );
        }

        $profile = $user->employerProfile;
        if (! $profile instanceof EmployerProfile) {
            return;
        }

        if ((int) $profile->company_id !== (int) $company->id) {
            throw new CompanyManagementException(
                'This employer belongs to another company.',
                'COMPANY_MEMBER_DIFFERENT_COMPANY',
            );
        }

        if ($profile->membership_status !== CompanyMembershipStatus::REMOVED) {
            throw new CompanyManagementException(
                'This user is already a company member.',
                'COMPANY_MEMBER_ALREADY_EXISTS',
            );
        }
    }

    private function assertPending(CompanyInvitation $invitation): void
    {
        if ($invitation->status === CompanyInvitationStatus::REVOKED) {
            throw new CompanyManagementException(
                'This company invitation has been revoked.',
                'COMPANY_INVITATION_REVOKED',
            );
        }
        if ($invitation->status === CompanyInvitationStatus::EXPIRED || $invitation->expires_at->isPast()) {
            throw new CompanyManagementException(
                'This company invitation has expired.',
                'COMPANY_INVITATION_EXPIRED',
            );
        }
        if ($invitation->status !== CompanyInvitationStatus::PENDING) {
            throw new CompanyManagementException(
                'This company invitation has already been used.',
                'COMPANY_INVITATION_ALREADY_USED',
            );
        }
    }

    private function markExpiredIfNeeded(CompanyInvitation $invitation): void
    {
        if ($invitation->status === CompanyInvitationStatus::PENDING && $invitation->expires_at->isPast()) {
            $invitation->forceFill(['status' => CompanyInvitationStatus::EXPIRED])->save();
            $invitation->refresh();
        }
    }

    private function expirePendingInvitations(Company $company): void
    {
        CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->where('status', CompanyInvitationStatus::PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => CompanyInvitationStatus::EXPIRED]);
    }

    private function findByToken(string $token): CompanyInvitation
    {
        $invitation = CompanyInvitation::query()
            ->where('token_hash', $this->hashToken($token))
            ->first();

        if (! $invitation instanceof CompanyInvitation) {
            throw new CompanyManagementException(
                'The company invitation could not be found.',
                'COMPANY_INVITATION_NOT_FOUND',
                404,
            );
        }

        return $invitation;
    }

    private function lockedByToken(string $token): CompanyInvitation
    {
        $invitation = CompanyInvitation::query()
            ->where('token_hash', $this->hashToken($token))
            ->lockForUpdate()
            ->first();

        if (! $invitation instanceof CompanyInvitation) {
            throw new CompanyManagementException(
                'The company invitation could not be found.',
                'COMPANY_INVITATION_NOT_FOUND',
                404,
            );
        }

        return $invitation;
    }

    private function notifyExistingUser(
        string $email,
        string $type,
        string $title,
        string $message,
        Company $company,
    ): void {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
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

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function newToken(): string
    {
        return Str::random(64);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
