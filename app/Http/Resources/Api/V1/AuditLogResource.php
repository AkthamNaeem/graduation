<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditLog;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => LocalizedValue::make($this->action, 'audit_actions'),
            'entity_type' => $this->entity_type,
            'entity' => LocalizedValue::make(
                Str::snake(class_basename($this->entity_type)),
                'audit_entities',
            ),
            'entity_id' => $this->entity_id,
            'actor_user_id' => $this->actor_user_id,
            'actor' => new UserResource($this->whenLoaded('actor')),
            'before_values' => $this->before_values,
            'after_values' => $this->after_values,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
