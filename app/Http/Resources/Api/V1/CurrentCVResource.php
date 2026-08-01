<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CandidateCVStage;
use App\Services\CV\CVFileActionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrentCVResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cv = is_array($this->resource) ? $this->resource['cv'] : $this->resource;
        $allowedActions = is_array($this->resource)
            ? $this->resource['allowed_actions']
            : app(CVFileActionResolver::class)->current($cv, false);

        return [
            'id' => $cv->id,
            'original_name' => $cv->original_name,
            'mime_type' => $cv->mime_type,
            'extension' => $cv->extension,
            'size_bytes' => $cv->size_bytes,
            'stage' => [
                'key' => CandidateCVStage::CONFIRMED->value,
                'label' => __('profile.cv_contract.stages.confirmed'),
            ],
            'confirmed_at' => $cv->confirmed_at?->toISOString(),
            'can_use_for_application' => $cv->isConfirmedUsableForApplication(),
            'allowed_actions' => $allowedActions,
            'created_at' => $cv->created_at?->toISOString(),
            'updated_at' => $cv->updated_at?->toISOString(),
        ];
    }
}
