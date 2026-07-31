<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Enums\UserRole;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class ShowCVSummaryRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $application = $this->route('jobApplication');
        $user = $this->authenticatedUser();

        return $application instanceof JobApplication
            && $user !== null
            && $user->role !== UserRole::JOB_SEEKER
            && $user->can('view', $application);
    }

    public function rules(): array
    {
        return [];
    }
}
