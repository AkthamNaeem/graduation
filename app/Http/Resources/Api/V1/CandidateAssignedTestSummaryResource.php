<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Test;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Test */
class CandidateAssignedTestSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'duration_minutes' => $this->duration_minutes,
            'question_count' => (int) ($this->questions_count ?? 0),
        ];
    }
}
