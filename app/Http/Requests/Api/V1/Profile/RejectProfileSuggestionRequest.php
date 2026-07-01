<?php

namespace App\Http\Requests\Api\V1\Profile;

class RejectProfileSuggestionRequest extends GenerateProfileSuggestionsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
