<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class ProfileCVStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ready = (bool) $this->resource['is_ready'];

        return [
            'status' => [
                'key' => $this->resource['status'],
                'label' => __("profile.cv_contract.statuses.{$this->resource['status']}"),
            ],
            'is_ready' => $ready,
            'pending_update' => $this->resource['pending_update'] === null
                ? null
                : PendingCVUpdateResource::make($this->resource['pending_update']),
            'allowed_actions' => $this->resource['allowed_actions'],
            'preview_url' => $ready ? route('v1.profile.cv.preview') : null,
            'download_url' => $ready ? route('v1.profile.cv.download') : null,
        ];
    }
}
