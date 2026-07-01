<?php

namespace App\Http\Requests\Api\V1\Profile;

class ApplyProfileSuggestionsRequest extends GenerateProfileSuggestionsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'suggestion_ids' => ['required', 'array', 'min:1'],
            'suggestion_ids.*' => ['integer', 'distinct'],
        ];
    }
}
