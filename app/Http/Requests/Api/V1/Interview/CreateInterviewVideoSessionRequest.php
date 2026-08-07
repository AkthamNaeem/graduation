<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Enums\UserRole;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;

class CreateInterviewVideoSessionRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $interview = $this->route('interview');
        $user = $this->authenticatedUser();

        return $interview instanceof Interview
            && $user !== null
            && in_array($user->role, [UserRole::JOB_SEEKER, UserRole::EMPLOYER], true)
            && $user->can('joinVideo', $interview);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'participant_identity' => ['prohibited'],
            'participant_name' => ['prohibited'],
            'room_name' => ['prohibited'],
            'room' => ['prohibited'],
        ];
    }
}
