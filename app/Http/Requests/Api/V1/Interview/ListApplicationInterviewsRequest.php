<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class ListApplicationInterviewsRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $jobApplication = $this->route('jobApplication');
        $user = $this->authenticatedUser();

        return $jobApplication instanceof JobApplication
            && ($user?->can('viewForApplication', [Interview::class, $jobApplication]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
