<?php

namespace App\Http\Requests\Api\V1\Application\InternalNote;

use App\Models\ApplicationInternalNote;

class ShowApplicationInternalNoteRequest extends InternalNoteRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        return $note instanceof ApplicationInternalNote && ($this->actor()?->can('view', $note) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
