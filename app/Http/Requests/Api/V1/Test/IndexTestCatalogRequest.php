<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestCatalog;
use Illuminate\Foundation\Http\FormRequest;

class IndexTestCatalogRequest extends FormRequest
{
    use AuthorizesTestCatalog;

    public function authorize(): bool
    {
        return $this->canReadTestCatalog();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
