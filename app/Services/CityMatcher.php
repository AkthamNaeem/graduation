<?php

namespace App\Services;

use App\Models\City;
use Illuminate\Support\Str;

class CityMatcher
{
    public function match(?string $location): ?City
    {
        $haystack = $this->normalize($location);
        if ($haystack === '') {
            return null;
        }

        $matches = City::query()
            ->where('country_code', 'SY')
            ->where('is_active', true)
            ->get()
            ->filter(function (City $city) use ($haystack): bool {
                foreach ([$city->name_ar, $city->name_en, $city->code] as $candidate) {
                    $needle = $this->normalize($candidate);
                    if ($needle !== '' && str_contains(' '.$haystack.' ', ' '.$needle.' ')) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = Str::lower(trim($value));
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = str_replace(['أ', 'إ', 'آ'], 'ا', $value);
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
