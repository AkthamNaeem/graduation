<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminUserStatusActionRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserStatusRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(IndexAdminUserRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';

        $users = User::query()
            ->with(['jobSeekerProfile.skills', 'employerProfile.company'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['created_from'] ?? null, fn ($query, string $createdFrom) => $query->whereDate('created_at', '>=', $createdFrom))
            ->when($filters['created_to'] ?? null, fn ($query, string $createdTo) => $query->whereDate('created_at', '<=', $createdTo))
            ->orderBy($sortBy, $sortDirection)
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: UserResource::collection($users),
            message: 'Users retrieved successfully.',
        );
    }

    public function show(IndexAdminUserRequest $request, User $user): JsonResponse
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
        return $this->setStatus($request, $user, (string) $request->validated('status'), 'User status updated successfully.');
    }

    public function activate(AdminUserStatusActionRequest $request, User $user): JsonResponse
    {
        return $this->setStatus($request, $user, 'active', 'User activated successfully.');
    }

    public function suspend(AdminUserStatusActionRequest $request, User $user): JsonResponse
    {
        return $this->setStatus($request, $user, 'suspended', 'User suspended successfully.', true);
    }

    private function setStatus(UpdateUserStatusRequest|AdminUserStatusActionRequest $request, User $user, string $status, string $message, bool $revokeTokens = false): JsonResponse
    {
        $before = $user->only(['status']);

        $user->forceFill(['status' => $status])->save();

        if ($revokeTokens) {
            $user->tokens()->delete();
        }

        $this->auditLogService->record(
            $status === 'active' ? 'user.activated' : 'user.suspended',
            $request->user('sanctum'),
            User::class,
            $user->id,
            $before,
            $user->only(['status']),
        );

        return ApiResponse::success(
            data: new UserResource($user->refresh()->load(['jobSeekerProfile.skills', 'employerProfile.company'])),
            message: $message,
        );
    }
}
