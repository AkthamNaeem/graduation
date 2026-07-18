<?php

namespace App\Http\Requests\Api\V1\CV;

use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveCVRequest extends FormRequest
{
    use AuthorizesProfileRoles;
    public function authorize(): bool { return $this->isJobSeeker(); }
    public function rules(): array { return ['replacement_cv_file_id' => ['nullable', 'integer']]; }
}
