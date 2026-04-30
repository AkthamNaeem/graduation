<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestCatalog;
use App\Models\Test;
use Illuminate\Foundation\Http\FormRequest;

class ShowTestCatalogRequest extends FormRequest
{
    use AuthorizesTestCatalog;

    public function authorize(): bool
    {
        $test = $this->route('test');

        return $test instanceof Test
            && $this->canReadTestCatalog()
            && ($test->is_active || $this->canManageTestCatalog());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
