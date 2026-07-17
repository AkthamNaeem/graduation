<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin \App\Models\TestAttempt */
class TestAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $request->bearerToken();
        $role = $token ? PersonalAccessToken::findToken($token)?->tokenable?->role : null;

        return [
            'id' => $this->id,
            'application_test_assignment_id' => $this->application_test_assignment_id,
            'answers' => TestAnswerResource::collection($this->whenLoaded('testAnswers')),
            'grading_status' => $this->grading_status?->value,
            'objective_score' => $this->objective_score,
            'objective_max_score' => $this->objective_max_score,
            'manual_score' => $this->manual_score,
            'manual_max_score' => $this->manual_max_score,
            'total_score' => $this->total_score,
            'max_score' => $this->max_score,
            'percentage' => $this->percentage,
            'is_passing_score_met' => $this->passingScoreMet(),
            'auto_graded_at' => $this->auto_graded_at?->toISOString(),
            'manually_graded_at' => $this->manually_graded_at?->toISOString(),
            'score' => $this->score,
            'feedback' => $this->feedback,
            'evaluated_by_user_id' => $this->when($role !== UserRole::JOB_SEEKER, $this->evaluated_by_user_id),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
