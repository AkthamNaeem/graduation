<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\ShowProfileRequest;
use App\Http\Requests\Api\V1\Profile\UpdateJobSeekerProfileRequest;
use App\Http\Resources\Api\V1\ProfilePageResource;
use App\Services\CV\CVDocumentService;
use App\Services\ProfilePageService;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly ProfilePageService $profilePageService,
        private readonly CVDocumentService $cvDocumentService,
    ) {}

    public function show(ShowProfileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new ProfilePageResource($this->profilePageService->get($request->user())),
            message: __('profile.page_retrieved'),
        );
    }

    public function update(UpdateJobSeekerProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->profileService->updateJobSeekerProfile($user, $request->validated());

        return ApiResponse::success(
            data: new ProfilePageResource($this->profilePageService->get($user)),
            message: __('profile.updated'),
        );
    }

    public function previewCV(ShowProfileRequest $request): Response
    {
        return $this->cvDocumentService->currentResponse($request->user(), download: false);
    }

    public function downloadCV(ShowProfileRequest $request): Response
    {
        return $this->cvDocumentService->currentResponse($request->user(), download: true);
    }
}
