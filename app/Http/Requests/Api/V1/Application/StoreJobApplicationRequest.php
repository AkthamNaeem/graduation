<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use Illuminate\Foundation\Http\FormRequest;

class StoreJobApplicationRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        return $this->isJobSeekerUser();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cv_file_id' => ['sometimes', 'nullable', 'integer'],
            'selected_cv_file_id' => [
                'sometimes',
                'nullable',
                'integer',
            ],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'consent_to_share_profile' => ['accepted'],
            'screening_answers' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cv_file_id') && ! $this->has('selected_cv_file_id')) {
            $this->merge(['selected_cv_file_id' => $this->input('cv_file_id')]);
        }
        if ($this->has('consent') && ! $this->has('consent_to_share_profile')) {
            $this->merge(['consent_to_share_profile' => $this->input('consent')]);
        }
    }
}
