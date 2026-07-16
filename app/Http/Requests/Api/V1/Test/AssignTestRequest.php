<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationTestAssignment;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTestRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $jobApplication = $this->route('jobApplication');
        $user = $this->authenticatedUser();

        return $jobApplication instanceof JobApplication
            && ($user?->can('assign', [ApplicationTestAssignment::class, $jobApplication]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $jobApplication = $this->route('jobApplication');
        $companyId = $jobApplication instanceof JobApplication
            ? $jobApplication->jobPosting?->company_id
            : null;

        return [
            'test_id' => [
                'required',
                'integer',
                Rule::exists('tests', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('company_id', $companyId)),
            ],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
