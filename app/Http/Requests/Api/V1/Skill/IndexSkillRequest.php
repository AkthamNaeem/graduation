<?php

namespace App\Http\Requests\Api\V1\Skill;

use Illuminate\Foundation\Http\FormRequest;

class IndexSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
