<?php

namespace App\Rules;

use App\Models\City;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ActiveSyrianCity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $city = City::query()->find($value);

        if (! $city instanceof City) {
            $fail(__('validation.custom_messages.city_not_found'));

            return;
        }

        if (! $city->is_active) {
            $fail(__('validation.custom_messages.city_inactive'));

            return;
        }

        if ($city->country_code !== 'SY') {
            $fail(__('validation.custom_messages.city_not_syrian'));
        }
    }
}
