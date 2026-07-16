<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestAnswers;
use Illuminate\Foundation\Http\FormRequest;

class DeleteTestAnswerRequest extends FormRequest
{
    use AuthorizesTestAnswers;

    public function authorize(): bool
    {
        return $this->canManageAnswers();
    }

    public function rules(): array
    {
        return [];
    }
}
