<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class ChangeApplicationStatusRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $jobApplication = $this->route('jobApplication');

        return $jobApplication instanceof JobApplication
            && ($this->authenticatedUser()?->can('changeStatus', $jobApplication) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'exists:application_statuses,slug'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
