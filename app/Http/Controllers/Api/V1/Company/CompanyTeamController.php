<?php

namespace App\Http\Controllers\Api\V1\Company;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Company\IndexCompanyInvitationsRequest;
use App\Http\Requests\Api\V1\Company\IndexCompanyMembersRequest;
use App\Http\Requests\Api\V1\Company\ManageCompanyInvitationRequest;
use App\Http\Requests\Api\V1\Company\StoreCompanyInvitationRequest;
use App\Http\Requests\Api\V1\Company\TransferCompanyOwnershipRequest;
use App\Http\Requests\Api\V1\Company\UpdateCompanyMemberRoleRequest;
use App\Http\Requests\Api\V1\Company\UpdateCompanyMemberStatusRequest;
use App\Http\Resources\Api\V1\CompanyInvitationResource;
use App\Http\Resources\Api\V1\CompanyMemberResource;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Services\CompanyInvitationService;
use App\Services\CompanyMembershipService;
use App\Services\CompanyPermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CompanyTeamController extends Controller
{
    public function __construct(
        private readonly CompanyPermissionService $permissionService,
        private readonly CompanyMembershipService $membershipService,
        private readonly CompanyInvitationService $invitationService,
    ) {}

    public function members(IndexCompanyMembersRequest $request): JsonResponse
    {
        $company = $this->permissionService->companyFor($request->user('sanctum'));

        return ApiResponse::success(
            data: CompanyMemberResource::collection(
                $this->membershipService->list($request->user('sanctum'), $company, $request->validated()),
            ),
            message: __('companies.members'),
        );
    }

    public function invitations(IndexCompanyInvitationsRequest $request): JsonResponse
    {
        $company = $this->permissionService->companyFor($request->user('sanctum'));

        return ApiResponse::success(
            data: CompanyInvitationResource::collection(
                $this->invitationService->list($request->user('sanctum'), $company, $request->validated()),
            ),
            message: __('companies.invitations'),
        );
    }

    public function invite(StoreCompanyInvitationRequest $request): JsonResponse
    {
        $company = $this->permissionService->companyFor($request->user('sanctum'));
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
            message: __('companies.invitation_created'),
            status: 201,
        );
    }

    public function resend(ManageCompanyInvitationRequest $request, CompanyInvitation $invitation): JsonResponse
    {
        $company = $this->permissionService->companyFor($request->user('sanctum'));
        abort_unless((int) $invitation->company_id === (int) $company->id, 404);
        $result = $this->invitationService->resend($request->user('sanctum'), $invitation);

        return ApiResponse::success(
            data: [
                'invitation' => new CompanyInvitationResource($result['invitation']),
                'token' => $result['token'],
            ],
            message: __('companies.invitation_resent'),
        );
    }

    public function revoke(ManageCompanyInvitationRequest $request, CompanyInvitation $invitation): JsonResponse
    {
        $company = $this->permissionService->companyFor($request->user('sanctum'));
        abort_unless((int) $invitation->company_id === (int) $company->id, 404);

        return ApiResponse::success(
            data: new CompanyInvitationResource(
                $this->invitationService->revoke($request->user('sanctum'), $invitation),
            ),
            message: __('companies.invitation_revoked'),
        );
    }

    public function updateRole(
        UpdateCompanyMemberRoleRequest $request,
        User $user,
    ): JsonResponse {
        $company = $this->permissionService->companyFor($request->user('sanctum'));

        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->updateRole(
                    $request->user('sanctum'),
                    $company,
                    $user,
                    CompanyRole::from($request->validated('company_role')),
                ),
            ),
            message: __('companies.member_role'),
        );
    }

    public function updateStatus(
        UpdateCompanyMemberStatusRequest $request,
        User $user,
    ): JsonResponse {
        $company = $this->permissionService->companyFor($request->user('sanctum'));

        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->updateStatus(
                    $request->user('sanctum'),
                    $company,
                    $user,
                    CompanyMembershipStatus::from($request->validated('membership_status')),
                ),
            ),
            message: __('companies.member_status'),
        );
    }

    public function remove(IndexCompanyMembersRequest $request, User $user): JsonResponse
    {
        $company = $this->permissionService->companyFor($request->user('sanctum'));

        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->remove($request->user('sanctum'), $company, $user),
            ),
            message: __('companies.member_removed'),
        );
    }

    public function transferOwnership(TransferCompanyOwnershipRequest $request): JsonResponse
    {
        $company = $this->permissionService->companyFor($request->user('sanctum'));

        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->membershipService->transferOwnership(
                    $request->user('sanctum'),
                    $company,
                    $request->validated(),
                ),
            ),
            message: __('companies.ownership'),
        );
    }
}
