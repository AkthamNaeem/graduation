<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CandidateCVOperation;
use App\Enums\CandidateCVStage;
use App\Models\CVFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class PendingCVUpdateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CVFile $cv */
        $cv = $this->resource['cv'];
        /** @var CandidateCVOperation $operation */
        $operation = $this->resource['operation'];
        /** @var CandidateCVStage $stage */
        $stage = $this->resource['stage'];
        $nextAction = $this->resource['next_action'];

        return [
            'id' => $cv->id,
            'original_name' => $cv->original_name,
            'mime_type' => $cv->mime_type,
            'extension' => $cv->extension,
            'size_bytes' => $cv->size_bytes,
            'operation' => [
                'key' => $operation->value,
                'label' => __("profile.cv_contract.operations.{$operation->value}"),
            ],
            'stage' => [
                'key' => $stage->value,
                'label' => __("profile.cv_contract.stages.{$stage->value}"),
            ],
            'progress' => $this->resource['progress'],
            'next_action' => $nextAction === null ? null : [
                'type' => [
                    'key' => $nextAction['type'],
                    'label' => __("profile.cv_contract.actions.{$nextAction['type']}"),
                ],
                'target' => $nextAction['target'],
                'is_actionable' => $nextAction['is_actionable'],
            ],
            'can_use_for_application' => false,
            'allowed_actions' => $this->resource['allowed_actions'],
            'created_at' => $cv->created_at?->toISOString(),
            'updated_at' => $cv->updated_at?->toISOString(),
        ];
    }
}
