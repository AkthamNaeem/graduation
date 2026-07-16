<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestStructure;
use Illuminate\Foundation\Http\FormRequest;

class IndexTestQuestionRequest extends FormRequest
{
    use AuthorizesTestStructure;

    public function authorize(): bool { return $this->canManageTestStructure(); }
    public function rules(): array { return []; }
}
