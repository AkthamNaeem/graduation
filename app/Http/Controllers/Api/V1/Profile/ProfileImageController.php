<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\UpdateAvatarRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\OptionalImageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileImageController extends Controller
{
    public function __construct(private readonly OptionalImageService $images) {}

    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->images->updateAvatar($request->user('sanctum'), $request->file('image'))),
            __('profile.avatar_updated'),
        );
    }

    public function destroyAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->images->removeAvatar($request->user('sanctum'))),
            __('profile.avatar_removed'),
        );
    }
}
