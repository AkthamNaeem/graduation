<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MyJobApplicationIndexRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:200'],
            'group' => ['sometimes', Rule::in(['all', 'active', 'requires_action', 'completed'])],
            'status' => ['sometimes', 'array', 'max:20'],
            'status.*' => ['string', 'distinct', Rule::exists('application_statuses', 'slug')],
            'sort_by' => ['sometimes', Rule::in(['priority', 'updated_at', 'created_at', 'last_status_changed_at', 'deadline'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'group.in' => __('applications.validation.group'),
            'sort_by.in' => __('applications.validation.sort_by'),
            'sort_direction.in' => __('applications.validation.sort_direction'),
            'status.*.exists' => __('applications.validation.status'),
        ];
    }
}
