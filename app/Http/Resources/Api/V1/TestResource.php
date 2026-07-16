<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Test */
class TestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'duration_minutes' => $this->duration_minutes,
            'max_score' => $this->max_score,
            'passing_score' => $this->passing_score,
            'is_active' => $this->is_active,
            'company' => CompanyResource::make($this->whenLoaded('company')),
            'questions' => TestQuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
