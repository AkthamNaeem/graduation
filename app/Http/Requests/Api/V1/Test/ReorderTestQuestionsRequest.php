<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestCatalog;
use App\Models\Test;
use Illuminate\Foundation\Http\FormRequest;

class ReorderTestQuestionsRequest extends FormRequest
{
    use AuthorizesTestCatalog;

    public function authorize(): bool
    {
        return $this->route('test') instanceof Test
            && $this->canManageTestCatalog();
    }

    public function rules(): array
    {
        return [
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.id' => ['required', 'integer', 'exists:test_questions,id'],
            'questions.*.order_index' => ['required', 'integer', 'min:0'],
        ];
    }
}
