<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CompanyApprovalRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Models\Company;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminCompanyController extends Controller
{
    public function index(IndexAdminRequest $request): JsonResponse
    {
        $companies = Company::query()
            ->with('employerProfiles.user')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: CompanyResource::collection($companies),
            message: 'Companies retrieved successfully.',
        );
    }

    public function approve(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($company, 'approved', 'Company approved successfully.');
    }

    public function reject(CompanyApprovalRequest $request, Company $company): JsonResponse
    {
        return $this->setApprovalStatus($company, 'rejected', 'Company rejected successfully.');
    }

    private function setApprovalStatus(Company $company, string $status, string $message): JsonResponse
    {
        $company->forceFill(['approval_status' => $status])->save();

        return ApiResponse::success(
            data: new CompanyResource($company->refresh()->load('employerProfiles.user')),
            message: $message,
        );
    }
}
