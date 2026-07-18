<?php

namespace App\Http\Requests\Api\V1\Application\InternalNote;

use App\Models\ApplicationInternalNote;
use App\Models\JobApplication;

class IndexApplicationInternalNoteRequest extends InternalNoteRequest
{
    public function authorize(): bool
    {
        $application = $this->route('jobApplication');

        return $application instanceof JobApplication
            && ($this->actor()?->can('viewAnyForApplication', [ApplicationInternalNote::class, $application]) ?? false);
    }

    public function rules(): array
    {
        return [
            'include_deleted' => ['sometimes', 'boolean'],
            'author_user_id' => ['sometimes', 'integer', 'min:1'],
            'sort_direction' => ['sometimes', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('include_deleted'))) {
            $value = filter_var($this->input('include_deleted'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $this->merge(['include_deleted' => $value]);
            }
        }
    }
}
