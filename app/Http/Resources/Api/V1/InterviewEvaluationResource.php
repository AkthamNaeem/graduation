<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InterviewEvaluation */
class InterviewEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'evaluated_by_user_id' => $this->evaluated_by_user_id,
            'recommendation' => $this->recommendation,
            'overall_comment' => $this->overall_comment,
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'items' => InterviewEvaluationItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
