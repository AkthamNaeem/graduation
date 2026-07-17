<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\EventEffectKeyFactory;
use App\Services\EventSideEffectService;
use App\Services\NotificationService;
use Closure;

abstract class IdempotentNotificationListener
{
    public function __construct(
        protected readonly NotificationService $notificationService,
        private readonly EventSideEffectService $sideEffects,
        private readonly EventEffectKeyFactory $effectKeys,
    ) {}

    protected function notificationOnce(
        string $effectNamespace,
        string $eventName,
        string $aggregateType,
        int|string $aggregateId,
        User $recipient,
        Closure $effect,
        int|string|null $occurrenceId = null,
    ): bool {
        return $this->sideEffects->executeOnce(
            effectKey: $this->effectKeys->notification(
                $effectNamespace,
                $aggregateType,
                $aggregateId,
                $recipient->id,
                $occurrenceId,
            ),
            eventName: $eventName,
            listenerName: static::class,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            recipientUserId: $recipient->id,
            effect: $effect,
        );
    }
}
