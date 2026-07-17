<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationInformationRequest;
use Illuminate\Foundation\Http\FormRequest;

class SubmitApplicationInformationResponseRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $model = $this->route('informationRequest');

        return $model instanceof ApplicationInformationRequest && ($this->authenticatedUser()?->can('respond', $model) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('message') && $this->input('message') !== null) {
            $this->merge(['message' => trim((string) $this->input('message'))]);
        }
    }

    public function rules(): array
    {
        return [
            'message' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,txt,zip,png,jpg,jpeg', 'extensions:pdf,doc,docx,txt,zip,png,jpg,jpeg'],
        ];
    }
}
