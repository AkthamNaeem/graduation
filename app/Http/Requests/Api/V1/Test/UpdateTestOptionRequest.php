<?php

namespace App\Http\Requests\Api\V1\Test;

class UpdateTestOptionRequest extends StoreTestOptionRequest
{
    public function authorize(): bool { return $this->canManageTestStructure(requireQuestion: true, requireOption: true); }
    public function rules(): array
    {
        return [
            'option_text' => ['sometimes', 'required', 'string'],
            'order_index' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_correct' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
