<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobApplicationRequest extends FormRequest
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
        $userId = $this->user('sanctum')?->id;

        return [
            'selected_cv_file_id' => [
                'required',
                'integer',
                Rule::exists('cv_files', 'id')->where(fn ($query) => $query->where('user_id', $userId)),
            ],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'consent_to_share_profile' => ['accepted'],
            'screening_answers' => ['nullable', 'array'],
        ];
    }
}
