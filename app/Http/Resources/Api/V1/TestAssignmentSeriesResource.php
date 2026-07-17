<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationTestAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin array{root:ApplicationTestAssignment, assignments:\Illuminate\Database\Eloquent\Collection} */
class TestAssignmentSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $root = $this->resource['root'];
        $assignments = $this->resource['assignments'];
        $latest = $assignments->sortByDesc('attempt_number')->first();
        $role = ($token = $request->bearerToken())
            ? PersonalAccessToken::findToken($token)?->tokenable?->role
            : null;
        $manager = $role === UserRole::EMPLOYER || $role === UserRole::ADMIN;

        return [
            'test_id' => $root->test_id,
            'series_root_assignment_id' => $root->id,
            'max_attempts' => $root->max_attempts,
            'attempts_used' => $assignments->count(),
            'attempts_remaining' => max(0, $root->max_attempts - $assignments->count()),
            'latest_assignment_id' => $latest?->id,
            'assignments' => $assignments->map(function (ApplicationTestAssignment $assignment) use ($latest, $manager): array {
                $attempt = $assignment->testAttempt;
                $item = [
                    'assignment_id' => $assignment->id,
                    'attempt_number' => $assignment->attempt_number,
                    'deadline_at' => $assignment->deadline_at?->toISOString(),
                    'submitted_at' => $attempt?->submitted_at?->toISOString(),
                    'grading_status' => $attempt?->grading_status?->value ?? 'pending',
                    'percentage' => $attempt?->percentage,
                    'is_latest' => $assignment->id === $latest?->id,
                    'is_superseded' => $assignment->id !== $latest?->id,
                ];

                if ($manager) {
                    $item['previous_assignment_id'] = $assignment->previous_assignment_id;
                    $item['retake_reason'] = $assignment->retake_reason;
                    $item['retake_granted_by'] = $assignment->retakeGrantedBy === null ? null : [
                        'id' => $assignment->retakeGrantedBy->id,
                        'name' => $assignment->retakeGrantedBy->name,
                    ];
                }

                return $item;
            })->values()->all(),
        ];
    }
}
