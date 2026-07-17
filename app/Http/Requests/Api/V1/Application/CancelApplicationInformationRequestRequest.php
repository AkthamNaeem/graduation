<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationInformationRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelApplicationInformationRequestRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $model = $this->route('informationRequest');

        return $model instanceof ApplicationInformationRequest && ($this->authenticatedUser()?->can('cancel', $model) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason') && $this->input('reason') !== null) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
