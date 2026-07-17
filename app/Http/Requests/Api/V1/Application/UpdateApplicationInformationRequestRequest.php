<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Http\Requests\Api\V1\Application\Concerns\ValidatesInformationRequestItems;
use App\Models\ApplicationInformationRequest;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationInformationRequestRequest extends FormRequest
{
    use ResolvesApplicationUser, ValidatesInformationRequestItems;

    public function authorize(): bool
    {
        $model = $this->route('informationRequest');

        return $model instanceof ApplicationInformationRequest && ($this->authenticatedUser()?->can('update', $model) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareInformationRequestInput();
    }

    public function rules(): array
    {
        return [
            'message' => ['sometimes', 'required', 'string', 'max:10000'],
            'requested_items' => ['sometimes', 'required', 'array', 'min:1'],
            'requested_items.*.label' => ['required', 'string', 'max:255'],
            'requested_items.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'requested_items.*.is_required' => ['sometimes', 'boolean'],
            'due_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
