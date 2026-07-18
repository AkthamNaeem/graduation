<?php

namespace App\Http\Requests\Api\V1\CV;

use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use Illuminate\Foundation\Http\FormRequest;

class UploadCVRequest extends FormRequest
{
    use AuthorizesProfileRoles;

    public function authorize(): bool
    {
        return $this->isJobSeeker();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:5120',
                'mimes:pdf,docx',
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip',
            ],
            'version_label' => ['nullable', 'string', 'max:150'],
            'make_primary' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('make_primary'))) {
            $value = filter_var($this->input('make_primary'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $this->merge(['make_primary' => $value]);
            }
        }
    }
}
