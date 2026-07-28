<?php

namespace App\Support\RecommendationMl;

use App\Exceptions\RecommendationMl\MlRecommendationContractException;
use App\Exceptions\RecommendationMl\MlRecommendationValidationException;
use Normalizer;

final class MlDataValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    public static function requestKeys(array $data, array $allowed, array $required): void
    {
        self::assertKeys($data, $allowed, $required, false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    public static function responseKeys(array $data, array $allowed, array $required): void
    {
        self::assertKeys($data, $allowed, $required, true);
    }

    public static function requestFailure(string $code = 'ML_REQUEST_INVALID'): never
    {
        throw new MlRecommendationValidationException(
            internalCode: $code,
            operation: 'rank',
        );
    }

    public static function contractFailure(
        string $code = 'ML_RESPONSE_CONTRACT_INVALID',
        ?string $requestId = null,
        string $operation = 'response',
    ): never {
        throw new MlRecommendationContractException(
            internalCode: $code,
            requestId: $requestId,
            operation: $operation,
        );
    }

    public static function finiteNumber(mixed $value, float $minimum, float $maximum): float
    {
        if ((! is_int($value) && ! is_float($value))
            || ! is_finite((float) $value)
            || $value < $minimum
            || $value > $maximum) {
            self::requestFailure();
        }

        return (float) $value;
    }

    public static function finiteResponseNumber(
        mixed $value,
        float $minimum = -INF,
        float $maximum = INF,
        ?string $requestId = null,
        string $operation = 'response',
    ): float {
        if ((! is_int($value) && ! is_float($value))
            || ! is_finite((float) $value)
            || $value < $minimum
            || $value > $maximum) {
            self::contractFailure(requestId: $requestId, operation: $operation);
        }

        return (float) $value;
    }

    public static function string(mixed $value, int $maximum, int $minimum = 1): string
    {
        if (! is_string($value)
            || mb_strlen($value) < $minimum
            || mb_strlen($value) > $maximum) {
            self::requestFailure();
        }

        return $value;
    }

    public static function responseString(
        mixed $value,
        int $maximum = 512,
        int $minimum = 1,
        ?string $requestId = null,
        string $operation = 'response',
    ): string {
        if (! is_string($value)
            || mb_strlen($value) < $minimum
            || mb_strlen($value) > $maximum) {
            self::contractFailure(requestId: $requestId, operation: $operation);
        }

        return $value;
    }

    public static function nullableString(mixed $value, int $maximum): ?string
    {
        return $value === null ? null : self::string($value, $maximum);
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value, int $maximumItems, int $maximumLength): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximumItems) {
            self::requestFailure();
        }

        return array_map(
            fn (mixed $item): string => self::string($item, $maximumLength),
            $value,
        );
    }

    public static function normalizeSkillName(string $value): string
    {
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_KC)
            : $value;
        $normalized = mb_strtolower((string) $normalized);
        $normalized = strtr($normalized, [
            "\u{2010}" => '-',
            "\u{2011}" => '-',
            "\u{2012}" => '-',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2212}" => '-',
        ]);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*-\s*/u', '-', trim($normalized)) ?? $normalized;

        if ($normalized === '' || mb_strlen($normalized) > 128) {
            self::requestFailure();
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private static function assertKeys(
        array $data,
        array $allowed,
        array $required,
        bool $response,
    ): void {
        $keys = array_keys($data);
        $unknown = array_diff($keys, $allowed);
        $missing = array_diff($required, $keys);

        if ($unknown !== [] || $missing !== []) {
            $response
                ? self::contractFailure()
                : self::requestFailure();
        }
    }
}
