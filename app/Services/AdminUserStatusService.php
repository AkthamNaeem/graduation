<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminUserStatusService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function transition(User $actor, User $target, UserStatus $status): User
    {
        return DB::transaction(function () use ($actor, $target, $status): User {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $previousStatus = $user->status;
            $tokensRevoked = 0;

            $user->forceFill(['status' => $status])->save();

            if ($status === UserStatus::SUSPENDED) {
                $tokensRevoked = $user->tokens()->count();
                $user->tokens()->delete();
            }

            $this->auditLogService->record(
                $status === UserStatus::ACTIVE ? 'user.activated' : 'user.suspended',
                $actor,
                User::class,
                $user->id,
                ['status' => $previousStatus->value],
                ['status' => $status->value],
                [
                    'user_id' => $user->id,
                    'previous_status' => $previousStatus->value,
                    'new_status' => $status->value,
                    'actor_id' => $actor->id,
                    'tokens_revoked_count' => $tokensRevoked,
                ],
            );

            return $user->refresh();
        });
    }
}
