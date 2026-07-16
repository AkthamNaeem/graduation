<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestStructure;
use Illuminate\Foundation\Http\FormRequest;

class StoreTestOptionRequest extends FormRequest
{
    use AuthorizesTestStructure;

    public function authorize(): bool { return $this->canManageTestStructure(requireQuestion: true); }
    public function rules(): array
    {
        return [
            'option_text' => ['required', 'string'],
            'order_index' => ['required', 'integer', 'min:0'],
            'is_correct' => ['required', 'boolean'],
        ];
    }
}
