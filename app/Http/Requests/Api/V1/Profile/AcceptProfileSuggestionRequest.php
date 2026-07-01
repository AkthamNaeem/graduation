<?php

namespace App\Http\Requests\Api\V1\Profile;

class AcceptProfileSuggestionRequest extends GenerateProfileSuggestionsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'edited_value' => ['sometimes', 'array'],
        ];
    }
}
