<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestCatalog;
use App\Models\Test;
use Illuminate\Foundation\Http\FormRequest;

class DeleteTestCatalogRequest extends FormRequest
{
    use AuthorizesTestCatalog;

    public function authorize(): bool
    {
        return $this->route('test') instanceof Test
            && $this->canManageTestCatalog();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
