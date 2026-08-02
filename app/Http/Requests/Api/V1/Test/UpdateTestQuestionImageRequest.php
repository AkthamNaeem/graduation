<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestStructure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTestQuestionImageRequest extends FormRequest
{
    use AuthorizesTestStructure;

    public function authorize(): bool
    {
        return $this->canManageTestStructure(requireQuestion: true);
    }

    public function rules(): array
    {
        return [
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
