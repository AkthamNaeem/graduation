<?php

namespace App\Http\Requests\Api\V1\Application\InternalNote;

use App\Models\ApplicationInternalNote;
use App\Models\JobApplication;

class StoreApplicationInternalNoteRequest extends InternalNoteRequest
{
    public function authorize(): bool
    {
        $application = $this->route('jobApplication');

        return $application instanceof JobApplication
            && ($this->actor()?->can('create', [ApplicationInternalNote::class, $application]) ?? false);
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000', $this->plainTextRule()]];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    private function plainTextRule(): \Closure
    {
        return fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && strip_tags($value) !== $value ? $fail('The body must contain plain text only.') : null;
    }
}
