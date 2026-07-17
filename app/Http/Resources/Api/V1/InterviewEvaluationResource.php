<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InterviewEvaluation */
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
                    'role' => $this->evaluatedBy->role?->value,
                ],
            ),
            'recommendation' => $this->recommendation,
            'overall_comment' => $this->overall_comment,
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'items' => InterviewEvaluationItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
