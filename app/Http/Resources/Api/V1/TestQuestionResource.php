<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TestQuestion;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestQuestion */
class TestQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->test_id,
            'question_text' => $this->question_text,
            'question_type' => LocalizedValue::make($this->question_type, 'test_question_types'),
            'order_index' => $this->order_index,
            'points' => $this->points,
            'is_required' => $this->is_required,
            'image_url' => $this->image_path === null ? null : route('v1.tests.questions.image.show', [
                'test' => $this->test_id,
                'question' => $this->id,
            ]),
            'options' => TestOptionResource::collection($this->whenLoaded('options')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
