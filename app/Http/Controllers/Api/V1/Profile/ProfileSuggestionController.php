<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\AcceptProfileSuggestionRequest;
use App\Http\Requests\Api\V1\Profile\ApplyProfileSuggestionsRequest;
use App\Http\Requests\Api\V1\Profile\GenerateProfileSuggestionsRequest;
use App\Http\Requests\Api\V1\Profile\RejectProfileSuggestionRequest;
use App\Http\Resources\Api\V1\ProfileChangeSuggestionResource;
use App\Models\CVFile;
use App\Models\ProfileChangeSuggestion;
use App\Services\ProfileSyncService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileSuggestionController extends Controller
{
    public function __construct(
        private readonly ProfileSyncService $profileSyncService,
    ) {
    }

    public function index(GenerateProfileSuggestionsRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            data: ProfileChangeSuggestionResource::collection($this->profileSyncService->suggestionsForCV($request->user(), $cvFile)),
            message: 'Profile suggestions retrieved successfully.',
        );
    }

    public function generate(GenerateProfileSuggestionsRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            data: ProfileChangeSuggestionResource::collection($this->profileSyncService->generateSuggestionsFromParsedCV($request->user(), $cvFile)),
            message: 'Profile suggestions generated successfully.',
            status: 201,
        );
    }

    public function accept(AcceptProfileSuggestionRequest $request, ProfileChangeSuggestion $suggestion): JsonResponse
    {
        return ApiResponse::success(
            data: new ProfileChangeSuggestionResource($this->profileSyncService->accept(
                $request->user(),
                $suggestion,
                $request->validated('edited_value'),
            )),
            message: 'Profile suggestion accepted and applied successfully.',
        );
    }

    public function reject(RejectProfileSuggestionRequest $request, ProfileChangeSuggestion $suggestion): JsonResponse
    {
        return ApiResponse::success(
            data: new ProfileChangeSuggestionResource($this->profileSyncService->reject(
                $request->user(),
                $suggestion,
                $request->validated('reason'),
            )),
            message: 'Profile suggestion rejected successfully.',
        );
    }

    public function applyBulk(ApplyProfileSuggestionsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: ProfileChangeSuggestionResource::collection($this->profileSyncService->applyBulk(
                $request->user(),
                $request->validated('suggestion_ids'),
            )),
            message: 'Accepted profile suggestions applied successfully.',
        );
    }
}
