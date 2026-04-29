<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationTestAssignment;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class ListApplicationTestsRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $jobApplication = $this->route('jobApplication');
        $user = $this->authenticatedUser();

        return $jobApplication instanceof JobApplication
            && ($user?->can('viewForApplication', [ApplicationTestAssignment::class, $jobApplication]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
