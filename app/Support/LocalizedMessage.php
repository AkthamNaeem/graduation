<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

class LocalizedMessage
{
    /** @var array<string, string> */
    private static array $keysByEnglishMessage = [];

    public static function resolve(string $message, ?string $code = null): string
    {
        if ($code !== null) {
            $codeKey = 'domain_errors.'.$code;

            if (Lang::has($codeKey)) {
                return __($codeKey);
            }
        }

        $key = self::keyFor($message);

        if ($key !== null) {
            return __($key);
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function resolveArray(array $values): array
    {
        return array_map(
            static function (mixed $value): mixed {
                if (is_array($value)) {
                    return self::resolveArray($value);
                }

                return is_string($value) ? self::resolve($value) : $value;
            },
            $values,
        );
    }

    private static function keyFor(string $message): ?string
    {
        if (self::$keysByEnglishMessage === []) {
            foreach ([
                'api', 'auth', 'profile', 'cv', 'jobs', 'applications', 'tests',
                'interviews', 'companies', 'notifications', 'admin', 'home', 'ai', 'errors',
                'domain_errors', 'validation_domain',
            ] as $domain) {
                $lines = Lang::get($domain, [], 'en');
                if (! is_array($lines)) {
                    continue;
                }

                foreach (Arr::dot($lines) as $key => $english) {
                    if (is_string($english)) {
                        self::$keysByEnglishMessage[$english] = $domain.'.'.$key;
                    }
                }
            }
        }

        return self::$keysByEnglishMessage[$message] ?? null;
    }
}
