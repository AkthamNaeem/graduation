<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexAuditLogRequest;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $auditLogs = AuditLog::query()
            ->with('actor')
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($filters['actor_user_id'] ?? null, fn ($query, int $actorUserId) => $query->where('actor_user_id', $actorUserId))
            ->when($filters['entity_type'] ?? null, fn ($query, string $entityType) => $query->where('entity_type', $entityType))
            ->when($filters['entity_id'] ?? null, fn ($query, int $entityId) => $query->where('entity_id', $entityId))
            ->when($filters['date_from'] ?? null, fn ($query, string $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn ($query, string $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: AuditLogResource::collection($auditLogs),
            message: 'Audit logs retrieved successfully.',
        );
    }
}
