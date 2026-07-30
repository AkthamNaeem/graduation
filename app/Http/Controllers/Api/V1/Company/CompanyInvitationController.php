<?php

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Company\AcceptCompanyInvitationRequest;
use App\Http\Resources\Api\V1\CompanyInvitationResource;
use App\Http\Resources\Api\V1\CompanyMemberResource;
use App\Models\User;
use App\Services\CompanyInvitationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CompanyInvitationController extends Controller
{
    public function __construct(
        private readonly CompanyInvitationService $invitationService,
    ) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitationService->inspect($token);

        return ApiResponse::success(
            data: [
                'company' => [
                    'id' => $invitation->company_id,
                    'name' => $invitation->company->name,
                ],
                'email' => $invitation->email,
                'company_role' => $invitation->company_role->value,
                'expires_at' => $invitation->expires_at?->toISOString(),
                'requires_registration' => ! User::query()
                    ->whereRaw('LOWER(email) = ?', [$invitation->email])
                    ->exists(),
            ],
            message: __('companies.invitation'),
        );
    }

    public function accept(AcceptCompanyInvitationRequest $request, string $token): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyMemberResource(
                $this->invitationService->accept($token, $request->validated()),
            ),
            message: __('companies.invitation_accepted'),
            status: 201,
        );
    }

    public function reject(string $token): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyInvitationResource($this->invitationService->reject($token)),
            message: __('companies.invitation_rejected'),
        );
    }
}
