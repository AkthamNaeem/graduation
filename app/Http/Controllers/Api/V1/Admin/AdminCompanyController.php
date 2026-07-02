<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CompanyApprovalRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminCompanyRequest;
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

    public function index(IndexAdminCompanyRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $companies = Company::query()
            ->with('employerProfiles.user')
            ->withCount(['employerProfiles', 'jobPostings'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('industry', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%");
                });
            })
            ->when($filters['approval_status'] ?? null, fn ($query, string $status) => $query->where('approval_status', $status))
            ->when($filters['industry'] ?? null, fn ($query, string $industry) => $query->where('industry', $industry))
            ->when($filters['created_from'] ?? null, fn ($query, string $createdFrom) => $query->whereDate('created_at', '>=', $createdFrom))
            ->when($filters['created_to'] ?? null, fn ($query, string $createdTo) => $query->whereDate('created_at', '<=', $createdTo))
            ->orderBy($sortBy, $sortDirection)
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: CompanyResource::collection($companies),
            message: 'Companies retrieved successfully.',
        );
    }

    public function show(IndexAdminCompanyRequest $request, Company $company): JsonResponse
    {
        $company->load('employerProfiles.user')
            ->loadCount(['employerProfiles', 'jobPostings'])
            ->loadCount([
                'jobPostings as applications_count' => fn ($query) => $query->join('job_applications', 'job_applications.job_posting_id', '=', 'job_postings.id'),
            ]);

        return ApiResponse::success(
            data: new CompanyResource($company),
            message: 'Company retrieved successfully.',
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
