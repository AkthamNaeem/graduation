<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message === null
                ? __('api.request_completed')
                : LocalizedMessage::resolve($message),
            'data' => self::resolveData($data),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function error(
        ?string $message = null,
        array $errors = [],
        int $status = 400,
        ?string $code = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message === null
                ? __('api.request_failed')
                : LocalizedMessage::resolve($message, $code),
            'errors' => LocalizedMessage::resolveArray($errors),
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
