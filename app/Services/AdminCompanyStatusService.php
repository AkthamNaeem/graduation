<?php

namespace App\Services;

use App\Enums\CompanyApprovalStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminCompanyStatusService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function transition(User $actor, Company $target, CompanyApprovalStatus $status): Company
    {
        return DB::transaction(function () use ($actor, $target, $status): Company {
            $company = Company::query()->lockForUpdate()->findOrFail($target->id);
            $previousStatus = (string) $company->approval_status;

            $company->forceFill(['approval_status' => $status->value])->save();

            $this->auditLogService->record(
                'company.'.$status->value,
                $actor,
                Company::class,
                $company->id,
                ['approval_status' => $previousStatus],
                ['approval_status' => $status->value],
                [
                    'company_id' => $company->id,
                    'previous_status' => $previousStatus,
                    'new_status' => $status->value,
                    'actor_id' => $actor->id,
                ],
            );

            return $company->refresh();
        });
    }
}
