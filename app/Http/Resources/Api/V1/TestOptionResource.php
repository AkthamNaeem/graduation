<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TestOption */
class TestOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $request->user('sanctum')?->role;

        return [
            'id' => $this->id,
            'test_question_id' => $this->test_question_id,
            'option_text' => $this->option_text,
            'order_index' => $this->order_index,
            'is_correct' => $this->when(
                $role === UserRole::EMPLOYER || $role === UserRole::ADMIN,
                $this->is_correct,
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
