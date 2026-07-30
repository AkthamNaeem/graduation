<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use App\Models\InterviewEvaluation;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InterviewEvaluation */
class InterviewEvaluationResource extends JsonResource
{
    use ResolvesResourceViewer;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! $this->viewerIsManager($request)) {
            return [];
        }

        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'evaluated_by_user_id' => $this->evaluated_by_user_id,
            'evaluated_by' => $this->when(
                $this->relationLoaded('evaluatedBy'),
                fn (): ?array => $this->evaluatedBy === null ? null : [
                    'id' => $this->evaluatedBy->id,
                    'name' => $this->evaluatedBy->name,
                    'role' => LocalizedValue::make($this->evaluatedBy->role, 'user_roles'),
                ],
            ),
            'recommendation' => LocalizedValue::make($this->recommendation, 'interview_recommendations'),
            'overall_comment' => $this->overall_comment,
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'items' => InterviewEvaluationItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
