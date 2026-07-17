<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => self::resolveData($data),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function error(
        string $message = 'Request failed.',
        array $errors = [],
        int $status = 400,
        ?string $code = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    private static function resolveData(mixed $data): mixed
    {
        if (
            $data instanceof ResourceCollection
            && $data->resource instanceof Paginator
        ) {
            return $data->toResponse(request())->getData(true);
        }

        return $data;
    }
}
