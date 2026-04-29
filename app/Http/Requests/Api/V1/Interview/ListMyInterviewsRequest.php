<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Enums\UserRole;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use Illuminate\Foundation\Http\FormRequest;

class ListMyInterviewsRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        return $this->authenticatedUser()?->role === UserRole::JOB_SEEKER;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
