<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestAnswers;
use Illuminate\Foundation\Http\FormRequest;

class IndexTestAnswerRequest extends FormRequest
{
    use AuthorizesTestAnswers;

    public function authorize(): bool
    {
        return $this->canViewAnswers();
    }

    public function rules(): array
    {
        return [];
    }
}
