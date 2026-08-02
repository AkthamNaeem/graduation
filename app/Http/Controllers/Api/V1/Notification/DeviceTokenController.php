<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\StoreDeviceTokenRequest;
use App\Models\DeviceToken;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $deviceToken = DeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'device_id' => $data['device_id'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'locale' => $data['locale'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
                'disabled_at' => null,
            ],
        );

        return ApiResponse::success(
            data: [
                'id' => $deviceToken->id,
                'platform' => $deviceToken->platform,
                'device_id' => $deviceToken->device_id,
                'is_active' => $deviceToken->is_active,
                'last_used_at' => $deviceToken->last_used_at?->toISOString(),
            ],
            message: 'Device token registered successfully.',
            status: $deviceToken->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, DeviceToken $deviceToken): JsonResponse
    {
        abort_unless($deviceToken->user_id === $request->user()->id, 404);

        $deviceToken->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
        ])->save();

        return ApiResponse::success(
            data: null,
            message: 'Device token disabled successfully.',
        );
    }
}
