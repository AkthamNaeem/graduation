<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TestAnswer */
class TestAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->test_question_id,
            'question_type' => $this->whenLoaded('question', fn () => $this->question->question_type->value),
            'answer_text' => $this->answer_text,
            'selected_options' => $this->whenLoaded('selectedOptions', fn () => $this->selectedOptions->map(fn ($option) => [
                'id' => $option->id,
                'option_text' => $option->option_text,
            ])->values()),
            'file' => $this->file_path === null ? null : [
                'original_name' => $this->file_original_name,
                'mime_type' => $this->file_mime_type,
                'size' => $this->file_size,
                'download_available' => true,
            ],
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
