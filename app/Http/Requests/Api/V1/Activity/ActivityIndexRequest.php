<?php

namespace App\Http\Requests\Api\V1\Activity;

use App\Enums\ActivityType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivityIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum')?->role === UserRole::JOB_SEEKER;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:200'],
            'group' => ['sometimes', Rule::in(['all', 'requires_action', 'today', 'this_week'])],
            'type' => ['sometimes', 'array', 'max:6'],
            'type.*' => ['string', 'distinct', Rule::in(ActivityType::values())],
            'sort_by' => ['sometimes', Rule::in(['priority', 'occurred_at', 'due_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'schedule_limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'group.in' => __('activity.validation.group'),
            'type.*.in' => __('activity.validation.type'),
            'sort_by.in' => __('activity.validation.sort_by'),
            'sort_direction.in' => __('activity.validation.sort_direction'),
            'timezone.timezone' => __('activity.validation.timezone'),
            'date_to.after_or_equal' => __('activity.validation.date_range'),
        ];
    }
}
