<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?User $actor,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): void {
        try {
            $request = request();

            AuditLog::query()->create([
                'actor_user_id' => $actor?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_values' => $before,
                'after_values' => $after,
                'metadata' => $metadata === [] ? null : $metadata,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Audit log recording failed.', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
