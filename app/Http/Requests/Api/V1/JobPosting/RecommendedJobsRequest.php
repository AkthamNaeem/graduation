<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use Illuminate\Foundation\Http\FormRequest;

class RecommendedJobsRequest extends FormRequest
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
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
