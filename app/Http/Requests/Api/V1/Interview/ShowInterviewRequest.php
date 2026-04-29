<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;

class ShowInterviewRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $interview = $this->route('interview');

        return $interview instanceof Interview
            && ($this->authenticatedUser()?->can('view', $interview) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
