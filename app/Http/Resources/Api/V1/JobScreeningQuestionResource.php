<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobScreeningQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_text' => $this->question_text,
            'question_type' => $this->question_type->value,
            'is_required' => $this->is_required,
            'sort_order' => $this->sort_order,
            'options' => JobScreeningQuestionOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
