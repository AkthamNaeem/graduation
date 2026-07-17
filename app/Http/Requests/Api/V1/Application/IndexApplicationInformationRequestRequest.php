<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationInformationRequest;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class IndexApplicationInformationRequestRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $model = $this->route('jobApplication');

        return $model instanceof JobApplication && ($this->authenticatedUser()?->can('viewAnyForApplication', [ApplicationInformationRequest::class, $model]) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
