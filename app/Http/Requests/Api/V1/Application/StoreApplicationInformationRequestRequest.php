<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Http\Requests\Api\V1\Application\Concerns\ValidatesInformationRequestItems;
use App\Models\ApplicationInformationRequest;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationInformationRequestRequest extends FormRequest
{
    use ResolvesApplicationUser, ValidatesInformationRequestItems;

    public function authorize(): bool
    {
        $application = $this->route('jobApplication');

        return $application instanceof JobApplication && ($this->authenticatedUser()?->can('create', [ApplicationInformationRequest::class, $application]) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareInformationRequestInput();
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:10000'],
            'requested_items' => ['required', 'array', 'min:1'],
            'requested_items.*.label' => ['required', 'string', 'max:255'],
            'requested_items.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'requested_items.*.is_required' => ['sometimes', 'boolean'],
            'due_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
