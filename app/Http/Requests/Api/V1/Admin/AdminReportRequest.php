<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminReportRequest extends FormRequest
{
    use AuthorizesAdmin;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'job_id' => ['sometimes', 'integer', 'exists:job_postings,id'],
            'status' => ['sometimes', 'string', 'max:100'],
            'group_by' => ['sometimes', 'string', Rule::in(['day', 'status'])],
        ];
    }
}
