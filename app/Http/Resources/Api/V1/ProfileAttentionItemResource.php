<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileAttentionItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = $this->resource['type'];
        $severity = $this->resource['severity'];
        $action = $this->resource['action'];

        return [
            'attention_key' => $this->resource['attention_key'],
            'type' => [
                'key' => $type,
                'label' => __("profile.attention.types.{$type}"),
            ],
            'title' => __("profile.attention.titles.{$type}"),
            'description' => __("profile.attention.descriptions.{$type}"),
            'priority' => $this->resource['priority'],
            'severity' => [
                'key' => $severity,
                'label' => __("profile.attention.severity.{$severity}"),
            ],
            'action' => $action === null ? null : [
                'type' => [
                    'key' => $action['type'],
                    'label' => __("profile.attention.actions.{$action['type']}"),
                ],
                'target' => $action['target'],
            ],
            ...array_key_exists('target', $this->resource)
                ? ['target' => $this->resource['target']]
                : [],
            ...array_key_exists('meta', $this->resource)
                ? ['meta' => $this->resource['meta']]
                : [],
        ];
    }
}
