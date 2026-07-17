<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationInformationRequest;
use Illuminate\Foundation\Http\FormRequest;

class ShowApplicationInformationRequestRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $model = $this->route('informationRequest');

        return $model instanceof ApplicationInformationRequest && ($this->authenticatedUser()?->can('view', $model) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
