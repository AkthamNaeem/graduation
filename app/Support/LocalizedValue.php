<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Support\Facades\Lang;
use LogicException;

final class LocalizedValue
{
    /**
     * @return array{key:string,value:string}|null
     */
    public static function make(BackedEnum|string|null $value, string $group): ?array
    {
        if ($value === null) {
            return null;
        }

        $key = $value instanceof BackedEnum
            ? (string) $value->value
            : (string) $value;
        $translationKey = "options.{$group}.{$key}";
        $locale = app()->getLocale();

        if (! Lang::has($translationKey, $locale, false)) {
            throw new LogicException("Missing localized option: {$translationKey} [{$locale}]");
        }

        return [
            'key' => $key,
            'value' => Lang::get($translationKey, [], $locale),
        ];
    }

    /**
     * @return array{key:string,value:string,count:int}
     */
    public static function count(string $key, int $count, string $group): array
    {
        return [
            ...self::make($key, $group),
            'count' => $count,
        ];
    }
}
