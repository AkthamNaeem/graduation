<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Test;
use App\Services\TestScorePolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Test */
class TestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $configuration = app(TestScorePolicyService::class)->buildScoreConfiguration($this->resource);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'duration_minutes' => $this->duration_minutes,
            'max_score' => $configuration['max_score'],
            'passing_score' => $this->passing_score,
            'passing_score_percentage' => $configuration['passing_score_percentage'],
            'question_count' => $configuration['question_count'],
            'score_configuration_valid' => $configuration['score_configuration_valid'],
            'is_active' => $this->is_active,
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'questions' => TestQuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
