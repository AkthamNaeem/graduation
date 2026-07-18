<?php

namespace App\Http\Requests\Api\V1\Application\InternalNote;

use App\Models\ApplicationInternalNote;

class IndexApplicationInternalNoteRevisionRequest extends InternalNoteRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        return $note instanceof ApplicationInternalNote && ($this->actor()?->can('viewRevisions', $note) ?? false);
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
