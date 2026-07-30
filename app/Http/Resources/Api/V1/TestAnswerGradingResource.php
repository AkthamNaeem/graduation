<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TestAnswerGrading;
use App\Support\LocalizedValue;
use App\Support\SystemGeneratedText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TestAnswerGrading */
class TestAnswerGradingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'grading_type' => LocalizedValue::make($this->grading_type, 'test_grading_types'),
            'is_correct' => $this->is_correct,
            'awarded_points' => $this->awarded_points,
            'max_points' => $this->max_points,
            'explanation' => SystemGeneratedText::resolve($this->explanation),
            'graded_at' => $this->graded_at?->toISOString(),
        ];
    }
}
