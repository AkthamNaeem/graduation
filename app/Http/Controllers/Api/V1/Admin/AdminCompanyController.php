<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CompanyApprovalRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminCompanyController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(IndexAdminRequest $request): JsonResponse
    {
        $companies = Company::query()
            ->with('employerProfiles.user')
            ->when($request->validated('approval_status'), fn ($query, string $status) => $query->where('approval_status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: CompanyResource::collection($companies),
            message: 'Companies retrieved successfully.',
        );
    }

    public function approve(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($request, $company, 'approved', 'company.approved', 'Company approved successfully.');
    }

    public function reject(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($request, $company, 'rejected', 'company.rejected', 'Company rejected successfully.');
    }

    public function suspend(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($request, $company, 'suspended', 'company.suspended', 'Company suspended successfully.');
    }

    private function setApprovalStatus(
        CompanyApprovalRequest $request,
        Company $company,
        string $status,
        string $action,
        string $message,
    ): JsonResponse {
        $before = $company->only(['approval_status']);

        $company->forceFill(['approval_status' => $status])->save();

        $this->auditLogService->record(
            $action,
            $request->user('sanctum'),
            Company::class,
            $company->id,
            $before,
            $company->only(['approval_status']),
        );

        return ApiResponse::success(
            data: new CompanyResource($company->refresh()->load('employerProfiles.user')),
            message: $message,
        );
    }
}
