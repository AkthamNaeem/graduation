<?php

namespace App\Services;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminCompanyService
{
    public function __construct(
        private readonly CompanyInvitationService $invitationService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{company: Company, owner_invitation: ?array}
     */
    public function create(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $owner = $data['owner'] ?? null;
            unset($data['owner']);
            $data['approval_status'] ??= CompanyApprovalStatus::PENDING->value;
            $data['owner_setup_required'] = true;

            $company = Company::query()->create($data);
            $ownerInvitation = null;
            if (is_array($owner)) {
                $ownerInvitation = $this->invitationService->invite($actor, $company, [
                    'email' => $owner['email'],
                    'company_role' => CompanyRole::OWNER->value,
                ]);
            }

            $this->auditLogService->record(
                'company.created',
                $actor,
                Company::class,
                $company->id,
                null,
                $company->only(['name', 'industry', 'website', 'location', 'approval_status']),
                ['company_id' => $company->id, 'owner_invitation_created' => $ownerInvitation !== null],
            );

            return [
                'company' => $this->loadSetupState($company),
                'owner_invitation' => $ownerInvitation,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Company $company, array $data): Company
    {
        return DB::transaction(function () use ($actor, $company, $data): Company {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            $before = $locked->only(array_keys($data));
            $locked->fill($data)->save();
            $this->auditLogService->record(
                'company.updated',
                $actor,
                Company::class,
                $locked->id,
                $before,
                $locked->only(array_keys($data)),
                ['company_id' => $locked->id],
            );

            return $this->loadSetupState($locked->refresh());
        });
    }

    public function loadSetupState(Company $company): Company
    {
        return $company->loadCount([
            'employerProfiles as owner_count' => fn ($query) => $query
                ->where('company_role', CompanyRole::OWNER)
                ->where('membership_status', CompanyMembershipStatus::ACTIVE),
        ]);
    }
}
