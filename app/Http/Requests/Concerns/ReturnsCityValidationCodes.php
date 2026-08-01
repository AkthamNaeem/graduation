<?php

namespace App\Http\Requests\Concerns;

use App\Models\City;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

trait ReturnsCityValidationCodes
{
    protected function failedValidation(Validator $validator): never
    {
        $errors = $validator->errors()->toArray();
        $attribute = collect(['city_id', 'profile.city_id', 'edited_value.city_id'])
            ->first(fn (string $key): bool => array_key_exists($key, $errors));
        $code = is_string($attribute)
            ? $this->cityValidationCode(data_get($this->all(), $attribute))
            : $this->additionalValidationCode($errors);

        throw new HttpResponseException(ApiResponse::error(
            message: __('api.validation_failed'),
            errors: $errors,
            status: 422,
            code: $code,
        ));
    }

    /** @param array<string, mixed> $errors */
    protected function additionalValidationCode(array $errors): ?string
    {
        return null;
    }

    private function cityValidationCode(mixed $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return 'INVALID_CITY_ID';
        }

        $city = City::query()->find((int) $value);
        if (! $city instanceof City) {
            return 'CITY_NOT_FOUND';
        }
        if (! $city->is_active) {
            return 'CITY_INACTIVE';
        }

        return $city->country_code === 'SY' ? 'INVALID_CITY_ID' : 'CITY_NOT_SYRIAN';
    }
}
