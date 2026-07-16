<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestStructure;
use Illuminate\Foundation\Http\FormRequest;

class ReorderTestOptionRequest extends FormRequest
{
    use AuthorizesTestStructure;

    public function authorize(): bool { return $this->canManageTestStructure(requireQuestion: true); }
    public function rules(): array
    {
        return [
            'options' => ['required', 'array'],
            'options.*.option_id' => ['required', 'integer', 'distinct'],
            'options.*.order_index' => ['required', 'integer', 'min:0', 'distinct'],
        ];
    }
}
