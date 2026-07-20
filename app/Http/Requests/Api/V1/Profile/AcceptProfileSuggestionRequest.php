<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Models\ProfileChangeSuggestion;
use Illuminate\Validation\Validator;

class AcceptProfileSuggestionRequest extends GenerateProfileSuggestionsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ProfileChangeSuggestion|null $suggestion */
        $suggestion = $this->route('suggestion');
        if (! $suggestion instanceof ProfileChangeSuggestion) {
            return ['edited_value' => ['sometimes', 'array']];
        }

        return match ($suggestion->entity_type) {
            ProfileChangeSuggestion::ENTITY_PROFILE => $this->profileRules($suggestion),
            ProfileChangeSuggestion::ENTITY_EXPERIENCE => $this->experienceRules(),
            ProfileChangeSuggestion::ENTITY_EDUCATION => $this->educationRules(),
            ProfileChangeSuggestion::ENTITY_SKILL => [
                'edited_value' => ['sometimes', 'array:name'],
                'edited_value.name' => ['required_with:edited_value', 'string', 'max:150'],
            ],
            default => ['edited_value' => ['prohibited']],
        };
    }

    private function profileRules(ProfileChangeSuggestion $suggestion): array
    {
        $field = array_key_first($suggestion->new_value ?? []);
        if (! in_array($field, ['phone', 'summary', 'location'], true)) {
            return ['edited_value' => ['prohibited']];
        }

        return [
            'edited_value' => ['sometimes', "array:{$field}"],
            "edited_value.{$field}" => ['required_with:edited_value', 'nullable', 'string', $field === 'summary' ? 'max:5000' : 'max:255'],
        ];
    }

    private function experienceRules(): array
    {
        return [
            'edited_value' => ['sometimes', 'array:title,company_name,location,start_date,end_date,is_current,description'],
            'edited_value.title' => ['required_with:edited_value', 'string', 'max:255'],
            'edited_value.company_name' => ['required_with:edited_value', 'string', 'max:255'],
            'edited_value.location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'edited_value.start_date' => ['sometimes', 'nullable', 'date'],
            'edited_value.end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:edited_value.start_date'],
            'edited_value.is_current' => ['sometimes', 'boolean'],
            'edited_value.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    private function educationRules(): array
    {
        return [
            'edited_value' => ['sometimes', 'array:institution,degree,field_of_study,start_date,end_date,description'],
            'edited_value.institution' => ['required_with:edited_value', 'string', 'max:255'],
            'edited_value.degree' => ['sometimes', 'nullable', 'string', 'max:255'],
            'edited_value.field_of_study' => ['sometimes', 'nullable', 'string', 'max:255'],
            'edited_value.start_date' => ['sometimes', 'nullable', 'date'],
            'edited_value.end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:edited_value.start_date'],
            'edited_value.description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $value = $this->input('edited_value');
            if (! is_array($value)) {
                return;
            }
            if (($value['is_current'] ?? false) && ($value['end_date'] ?? null) !== null) {
                $validator->errors()->add('edited_value.end_date', 'The end date must be null for a current experience.');
            }
        }];
    }
}
