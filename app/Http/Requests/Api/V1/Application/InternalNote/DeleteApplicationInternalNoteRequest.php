<?php

namespace App\Http\Requests\Api\V1\Application\InternalNote;

use App\Models\ApplicationInternalNote;

class DeleteApplicationInternalNoteRequest extends InternalNoteRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        return $note instanceof ApplicationInternalNote && ($this->actor()?->can('delete', $note) ?? false);
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }

    protected function failedAuthorization(): void
    {
        $this->fail('APPLICATION_INTERNAL_NOTE_AUTHOR_ONLY');
    }
}
