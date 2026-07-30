<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Company\IndexCompanyInvitationsRequest;
use App\Http\Requests\Api\V1\Company\IndexCompanyMembersRequest;
use App\Http\Requests\Api\V1\Company\StoreCompanyInvitationRequest;
use App\Http\Requests\Api\V1\Company\TransferCompanyOwnershipRequest;
use App\Http\Requests\Api\V1\Company\UpdateCompanyMemberRoleRequest;
use App\Http\Requests\Api\V1\Company\UpdateCompanyMemberStatusRequest;
use App\Http\Resources\Api\V1\CompanyInvitationResource;
use App\Http\Resources\Api\V1\CompanyMemberResource;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyInvitationService;
use App\Services\CompanyMembershipService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminCompanyMemberController extends Controller
{
    public function __construct(
        private readonly CompanyMembershipService $membershipService,
        private readonly CompanyInvitationService $invitationService,
    ) {}

    public function members(IndexCompanyMembersRequest $request, Company $company): JsonResponse
    {
        return ApiResponse::success(
            data: CompanyMemberResource::collection(
                $this->membershipService->list($request->user('sanctum'), $company, $request->validated()),
            ),
            message: 'Company members retrieved successfully.',
        );
    }

    public function invitations(IndexCompanyInvitationsRequest $request, Company $company): JsonResponse
    {
        return ApiResponse::success(
            data: CompanyInvitationResource::collection(
                $this->invitationService->list($request->user('sanctum'), $company, $request->validated()),
            ),
            message: 'Company invitations retrieved successfully.',
        );
    }

    public function invite(StoreCompanyInvitationRequest $request, Company $company): JsonResponse
    {
        $result = $this->invitationService->invite(
            $request->user('sanctum'),
            $company,
            $request->validated(),
        );

        return ApiResponse::success(
            data: [
                'invitation' => new CompanyInvitationResource($result['invitation']),
                'token' => $result['token'],
            ],
            message: 'Company invitation created successfully.',
            status: 201,
        );
    }

    public function updateRole(
        UpdateCompanyMemberRoleRequest $request,
        Company $company,
        User $user,
    ): JsonResponse {
        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->updateRole(
                    $request->user('sanctum'),
                    $company,
                    $user,
                    CompanyRole::from($request->validated('company_role')),
                ),
            ),
            message: 'Company member role updated successfully.',
        );
    }

    public function updateStatus(
        UpdateCompanyMemberStatusRequest $request,
        Company $company,
        User $user,
    ): JsonResponse {
        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->updateStatus(
                    $request->user('sanctum'),
                    $company,
                    $user,
                    CompanyMembershipStatus::from($request->validated('membership_status')),
                ),
            ),
            message: 'Company member status updated successfully.',
        );
    }

    public function remove(IndexCompanyMembersRequest $request, Company $company, User $user): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->remove($request->user('sanctum'), $company, $user),
            ),
            message: 'Company member removed successfully.',
        );
    }

    public function transferOwnership(
        TransferCompanyOwnershipRequest $request,
        Company $company,
    ): JsonResponse {
        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->transferOwnership(
                    $request->user('sanctum'),
                    $company,
                    $request->validated(),
                ),
            ),
            message: 'Company ownership transferred successfully.',
        );
    }
}
