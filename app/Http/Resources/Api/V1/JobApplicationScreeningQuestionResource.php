<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ScreeningQuestionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationScreeningQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $answer = $this->relationLoaded('answer') ? $this->answer : null;
        $value = match ($this->question_type) {
            ScreeningQuestionType::SHORT_TEXT, ScreeningQuestionType::LONG_TEXT => $answer?->text_value,
            ScreeningQuestionType::NUMBER => $answer?->number_value === null ? null : (float) $answer->number_value,
            ScreeningQuestionType::BOOLEAN => $answer?->boolean_value,
            ScreeningQuestionType::SINGLE_CHOICE, ScreeningQuestionType::MULTIPLE_CHOICE => null,
        };

        $selectedOptions = [];
        if ($answer !== null && $answer->relationLoaded('selectedOptions')) {
            $selectedOptions = $answer->selectedOptions
                ->filter(static fn ($selection): bool => $selection->relationLoaded('option') && $selection->option !== null)
                ->map(static fn ($selection): array => ['option_text' => $selection->option->option_text])
                ->values()
                ->all();
        }

        return [
            'question_text' => $this->question_text,
            'question_type' => $this->question_type->value,
            'is_required' => $this->is_required,
            'sort_order' => $this->sort_order,
            'answer' => [
                'value' => $value,
                'selected_options' => $selectedOptions,
            ],
        ];
    }
}
