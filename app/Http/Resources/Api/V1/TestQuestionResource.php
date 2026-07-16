<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TestQuestion */
class TestQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->test_id,
            'question_text' => $this->question_text,
            'question_type' => $this->question_type?->value,
            'order_index' => $this->order_index,
            'points' => $this->points,
            'is_required' => $this->is_required,
            'options' => TestOptionResource::collection($this->whenLoaded('options')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
