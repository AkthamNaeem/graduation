<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

class EventSideEffectService
{
    public function executeOnce(
        string $effectKey,
        string $eventName,
        string $listenerName,
        ?string $aggregateType,
        int|string|null $aggregateId,
        int|string|null $recipientUserId,
        Closure $effect,
    ): bool {
        return DB::transaction(function () use ($effectKey, $eventName, $listenerName, $aggregateType, $aggregateId, $recipientUserId, $effect): bool {
            $now = now();
            $inserted = DB::table('event_side_effect_executions')->insertOrIgnore([
                'effect_key' => $effectKey,
                'event_name' => $eventName,
                'listener_name' => $listenerName,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'recipient_user_id' => $recipientUserId,
                'executed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return false;
            }

            $effect();

            DB::table('event_side_effect_executions')
                ->where('effect_key', $effectKey)
                ->update([
                    'executed_at' => now(),
                    'updated_at' => now(),
                ]);

            return true;
        }, 3);
    }
}
