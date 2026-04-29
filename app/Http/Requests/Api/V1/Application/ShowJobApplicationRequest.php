<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class ShowJobApplicationRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $jobApplication = $this->route('jobApplication');

        return $jobApplication instanceof JobApplication
            && ($this->authenticatedUser()?->can('view', $jobApplication) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
