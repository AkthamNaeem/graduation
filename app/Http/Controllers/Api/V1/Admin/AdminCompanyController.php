<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminStoreCompanyRequest;
use App\Http\Requests\Api\V1\Admin\AdminUpdateCompanyRequest;
use App\Http\Requests\Api\V1\Admin\CompanyApprovalRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminCompanyRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Models\Company;
use App\Services\AdminCompanyService;
use App\Services\AdminCompanyStatusService;
use App\Support\ApiResponse;
use App\Support\LocalizedValue;
use Illuminate\Http\JsonResponse;

class AdminCompanyController extends Controller
{
    public function __construct(
        private readonly AdminCompanyStatusService $adminCompanyStatusService,
        private readonly AdminCompanyService $adminCompanyService,
    ) {}

    public function store(AdminStoreCompanyRequest $request): JsonResponse
    {
        $result = $this->adminCompanyService->create(
            $request->user('sanctum'),
            $request->validated(),
        );

        return ApiResponse::success(
            data: [
                'company' => new CompanyResource($result['company']),
                'owner_invitation' => $result['owner_invitation'] === null
                    ? null
                    : [
                        'id' => $result['owner_invitation']['invitation']->id,
                        'email' => $result['owner_invitation']['invitation']->email,
                        'company_role' => LocalizedValue::make(
                            $result['owner_invitation']['invitation']->company_role,
                            'company_roles',
                        ),
                        'expires_at' => $result['owner_invitation']['invitation']->expires_at?->toISOString(),
                        'token' => $result['owner_invitation']['token'],
                    ],
            ],
            message: __('admin.company_created'),
            status: 201,
        );
    }

    public function index(IndexAdminCompanyRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $companies = Company::query()
            ->with('employerProfiles.user')
            ->withCount(['employerProfiles', 'jobPostings'])
            ->withCount([
                'employerProfiles as owner_count' => fn ($query) => $query
                    ->where('company_role', CompanyRole::OWNER)
                    ->where('membership_status', CompanyMembershipStatus::ACTIVE),
            ])
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
            message: __('admin.companies'),
        );
    }

    public function show(IndexAdminCompanyRequest $request, Company $company): JsonResponse
    {
        $company->load('employerProfiles.user')
            ->loadCount(['employerProfiles', 'jobPostings'])
            ->loadCount([
                'jobPostings as applications_count' => fn ($query) => $query->join('job_applications', 'job_applications.job_posting_id', '=', 'job_postings.id'),
                'employerProfiles as owner_count' => fn ($query) => $query
                    ->where('company_role', CompanyRole::OWNER)
                    ->where('membership_status', CompanyMembershipStatus::ACTIVE),
            ]);

        return ApiResponse::success(
            data: new CompanyResource($company),
            message: __('companies.retrieved'),
        );
    }

    public function update(AdminUpdateCompanyRequest $request, Company $company): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyResource(
                $this->adminCompanyService->update(
                    $request->user('sanctum'),
                    $company,
                    $request->validated(),
                ),
            ),
            message: __('companies.updated'),
        );
    }

    public function approve(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($request, $company, CompanyApprovalStatus::APPROVED, __('admin.company_approved'));
    }

    public function reject(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($request, $company, CompanyApprovalStatus::REJECTED, __('admin.company_rejected'));
    }

    public function suspend(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($request, $company, CompanyApprovalStatus::SUSPENDED, __('admin.company_suspended'));
    }

    private function setApprovalStatus(
        CompanyApprovalRequest $request,
        Company $company,
        CompanyApprovalStatus $status,
        string $message,
    ): JsonResponse {
        $company = $this->adminCompanyStatusService->transition($request->user('sanctum'), $company, $status);

        return ApiResponse::success(
            data: new CompanyResource($company->load('employerProfiles.user')),
            message: $message,
        );
    }
}
