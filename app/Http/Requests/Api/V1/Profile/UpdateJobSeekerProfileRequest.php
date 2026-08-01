<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Enums\JobSeekerAvailabilityStatus;
use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use App\Http\Requests\Concerns\ReturnsCityValidationCodes;
use App\Rules\ActiveSyrianCity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateJobSeekerProfileRequest extends FormRequest
{
    use AuthorizesProfileRoles;
    use ReturnsCityValidationCodes;

    public function authorize(): bool
    {
        return $this->isJobSeeker();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'filled', 'string', 'max:255'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city_id' => ['bail', 'sometimes', 'nullable', 'integer', new ActiveSyrianCity],
            'availability_status' => ['bail', 'sometimes', 'nullable', new Enum(JobSeekerAvailabilityStatus::class)],
            'available_from' => ['bail', 'sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'portfolio_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'github_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['availability_status', 'available_from'])) {
                return;
            }

            $profile = $this->user()?->jobSeekerProfile;
            $storedStatus = $profile?->getRawOriginal('availability_status');
            $storedDate = $profile?->available_from?->format('Y-m-d');
            $status = $this->exists('availability_status')
                ? $this->input('availability_status')
                : $storedStatus;
            $availableFrom = $this->exists('available_from')
                ? $this->input('available_from')
                : $storedDate;

            if ($status === JobSeekerAvailabilityStatus::AVAILABLE_FROM_DATE->value) {
                if (! is_string($availableFrom) || $availableFrom === '') {
                    $validator->errors()->add('available_from', __('profile.availability.validation.date_required'));
                }

                return;
            }

            if ($availableFrom !== null && $availableFrom !== '') {
                $validator->errors()->add('available_from', __('profile.availability.validation.date_not_allowed'));
            }
        }];
    }

    /** @param array<string, mixed> $errors */
    protected function additionalValidationCode(array $errors): ?string
    {
        if (array_key_exists('availability_status', $errors)) {
            return 'PROFILE_AVAILABILITY_STATUS_INVALID';
        }
        if (! array_key_exists('available_from', $errors)) {
            return null;
        }

        $profile = $this->user()?->jobSeekerProfile;
        $status = $this->exists('availability_status')
            ? $this->input('availability_status')
            : $profile?->getRawOriginal('availability_status');
        $date = $this->input('available_from');

        if ($status !== JobSeekerAvailabilityStatus::AVAILABLE_FROM_DATE->value) {
            return 'PROFILE_AVAILABILITY_DATE_NOT_ALLOWED';
        }

        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date < now()->toDateString()) {
            return 'PROFILE_AVAILABILITY_DATE_IN_PAST';
        }

        return 'PROFILE_AVAILABILITY_DATE_REQUIRED';
    }
}
