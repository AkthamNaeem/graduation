<?php

namespace App\Http\Requests\Api\V1\CV;

use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCVMetadataRequest extends FormRequest
{
    use AuthorizesProfileRoles;
    public function authorize(): bool { return $this->isJobSeeker(); }
    public function rules(): array { return ['version_label' => ['present', 'nullable', 'string', 'max:150']]; }
}
