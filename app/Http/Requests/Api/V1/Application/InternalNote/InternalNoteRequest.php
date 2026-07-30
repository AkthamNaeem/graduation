<?php

namespace App\Http\Requests\Api\V1\Application\InternalNote;

use App\Exceptions\ApplicationInternalNoteException;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class InternalNoteRequest extends FormRequest
{
    use ResolvesApplicationUser;

    protected function actor(): ?User
    {
        return $this->authenticatedUser();
    }

    protected function fail(string $code = 'APPLICATION_INTERNAL_NOTE_NOT_OWNED'): never
    {
        throw new ApplicationInternalNoteException(__('validation.custom_messages.internal_note_unauthorized'), $code, 403);
    }
}
