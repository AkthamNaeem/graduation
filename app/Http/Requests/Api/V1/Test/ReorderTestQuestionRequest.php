<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestStructure;
use Illuminate\Foundation\Http\FormRequest;

class ReorderTestQuestionRequest extends FormRequest
{
    use AuthorizesTestStructure;

    public function authorize(): bool { return $this->canManageTestStructure(); }
    public function rules(): array
    {
        return [
            'questions' => ['required', 'array'],
            'questions.*.question_id' => ['required', 'integer', 'distinct'],
            'questions.*.order_index' => ['required', 'integer', 'min:0', 'distinct'],
        ];
    }
}
