<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TestQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestQuestion */
class CandidateTestQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_text' => $this->question_text,
            'question_type' => $this->question_type?->value,
            'order_index' => $this->order_index,
            'is_required' => $this->is_required,
            'options' => CandidateTestOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
