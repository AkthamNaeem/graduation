<?php

namespace App\Http\Requests\Api\V1\Application\InternalNote;

use App\Models\ApplicationInternalNote;

class UpdateApplicationInternalNoteRequest extends InternalNoteRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        return $note instanceof ApplicationInternalNote && ($this->actor()?->can('update', $note) ?? false);
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000', $this->plainTextRule()], 'version' => ['required', 'integer', 'min:1']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }

    protected function failedAuthorization(): void
    {
        $this->fail('APPLICATION_INTERNAL_NOTE_AUTHOR_ONLY');
    }

    private function plainTextRule(): \Closure
    {
        return fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && strip_tags($value) !== $value ? $fail('The body must contain plain text only.') : null;
    }
}
