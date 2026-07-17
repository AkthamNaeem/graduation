<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TestAnswerGrading */
class TestAnswerGradingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grading_type' => $this->grading_type?->value,
            'is_correct' => $this->is_correct,
            'awarded_points' => $this->awarded_points,
            'max_points' => $this->max_points,
            'explanation' => $this->explanation,
            'graded_at' => $this->graded_at?->toISOString(),
        ];
    }
}
