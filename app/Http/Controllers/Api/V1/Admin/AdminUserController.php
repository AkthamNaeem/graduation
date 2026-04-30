<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexAdminRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function index(IndexAdminRequest $request): JsonResponse
    {
        $users = User::query()
            ->with(['jobSeekerProfile.skills', 'employerProfile.company'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: UserResource::collection($users),
            message: 'Users retrieved successfully.',
        );
    }

    public function show(IndexAdminRequest $request, User $user): JsonResponse
    {
        return ApiResponse::success(
            data: new UserResource($user->load(['jobSeekerProfile.skills', 'employerProfile.company'])),
            message: 'User retrieved successfully.',
        );
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $user->forceFill(['role' => $request->validated('role')])->save();

        return ApiResponse::success(
            data: new UserResource($user->refresh()->load(['jobSeekerProfile.skills', 'employerProfile.company'])),
            message: 'User role updated successfully.',
        );
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $user->forceFill(['status' => $request->validated('status')])->save();

        return ApiResponse::success(
            data: new UserResource($user->refresh()->load(['jobSeekerProfile.skills', 'employerProfile.company'])),
            message: 'User status updated successfully.',
        );
    }
}
