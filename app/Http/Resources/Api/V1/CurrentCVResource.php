<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CandidateCVStage;
use App\Models\CVFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CVFile */
class CurrentCVResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'stage' => [
                'key' => CandidateCVStage::CONFIRMED->value,
                'label' => __('profile.cv_contract.stages.confirmed'),
            ],
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'can_use_for_application' => $this->isConfirmedUsableForApplication(),
            'allowed_actions' => ['view', 'download', 'update'],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
