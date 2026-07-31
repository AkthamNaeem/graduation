<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class GenerateCVSummaryRequest extends FormRequest
{
    use ResolvesApplicationUser;

    protected function prepareForValidation(): void
    {
        if ($this->has('force')) {
            $this->merge(['force' => $this->boolean('force')]);
        }
    }

    public function authorize(): bool
    {
        $application = $this->route('jobApplication');

        return $application instanceof JobApplication
            && ($this->authenticatedUser()?->can('generateCVSummary', $application) ?? false);
    }

    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
