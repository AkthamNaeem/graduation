<?php

namespace App\Http\Controllers\Api\V1\CV;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CV\ArchiveCVRequest;
use App\Http\Requests\Api\V1\CV\ConfirmCVRequest;
use App\Http\Requests\Api\V1\CV\CVIndexRequest;
use App\Http\Requests\Api\V1\CV\CVLifecycleRequest;
use App\Http\Requests\Api\V1\CV\ShowCVRequest;
use App\Http\Requests\Api\V1\CV\ShowCVReviewRequest;
use App\Http\Requests\Api\V1\CV\ShowParsedCVRequest;
use App\Http\Requests\Api\V1\CV\UpdateCVMetadataRequest;
use App\Http\Requests\Api\V1\CV\UpdateCVReviewDraftRequest;
use App\Http\Requests\Api\V1\CV\UploadCVRequest;
use App\Http\Resources\Api\V1\CurrentCVResource;
use App\Http\Resources\Api\V1\CVFileResource;
use App\Http\Resources\Api\V1\CVParsingResultResource;
use App\Http\Resources\Api\V1\CVReviewResource;
use App\Http\Resources\Api\V1\JobSeekerProfileResource;
use App\Http\Resources\Api\V1\ProfileChangeSuggestionResource;
use App\Models\CVFile;
use App\Services\CVService;
use App\Services\PrivateFileStorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CVController extends Controller
{
    public function __construct(
        private readonly CVService $cvService,
        private readonly PrivateFileStorageService $privateStorage,
    ) {}

    public function index(CVIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: CVFileResource::collection($this->cvService->list($request->user(), $request->integer('per_page', 15))),
            message: __('cv.list_retrieved'),
        );
    }

    public function upload(UploadCVRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CVFileResource($this->cvService->upload(
                $request->user(),
                $request->file('file'),
                $request->validated('version_label'),
                $request->boolean('make_primary'),
            )),
            message: __('cv.uploaded'),
            status: 201,
        );
    }

    public function update(UpdateCVMetadataRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->updateLabel($request->user(), $cvFile, $request->validated('version_label'))), __('cv.metadata_updated'));
    }

    public function makePrimary(CVLifecycleRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->makePrimary($request->user(), $cvFile)), __('cv.primary_updated'));
    }

    public function archive(ArchiveCVRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->archive($request->user(), $cvFile, $request->validated('replacement_cv_file_id'))), __('cv.archived'));
    }

    public function restore(CVLifecycleRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->restore($request->user(), $cvFile)), __('cv.restored'));
    }

    public function download(CVLifecycleRequest $request, CVFile $cvFile): StreamedResponse
    {
        $cvFile = $this->cvService->downloadable($request->user(), $cvFile);

        return $this->privateStorage->downloadResponse($cvFile->disk, $cvFile->stored_path, $cvFile->original_name, $cvFile->mime_type);
    }

    public function show(ShowCVRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            data: new CVFileResource($this->cvService->get($request->user(), $cvFile)),
            message: __('cv.retrieved'),
        );
    }

    public function parsed(ShowParsedCVRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            data: new CVParsingResultResource($this->cvService->getParsedResult($request->user(), $cvFile)),
            message: __('cv.parsing_retrieved'),
        );
    }

    public function review(ShowCVReviewRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            new CVReviewResource($this->cvService->getReview($request->user(), $cvFile)),
            __('cv.review_retrieved'),
        );
    }

    public function updateReviewDraft(UpdateCVReviewDraftRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            new CVReviewResource($this->cvService->updateReviewDraft($request->user(), $cvFile, $request->validated())),
            __('cv.review_updated'),
        );
    }

    public function readyForConfirmation(CVLifecycleRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            new CVReviewResource($this->cvService->readyForConfirmation($request->user(), $cvFile)),
            __('cv.ready_for_confirmation'),
        );
    }

    public function cancel(CVLifecycleRequest $request, CVFile $cvFile): JsonResponse
    {
        $result = $this->cvService->cancel($request->user(), $cvFile);
        $profile = $request->user()->jobSeekerProfile()->with('primaryCVFile')->firstOrFail();
        $current = $profile->primaryCVFile?->isConfirmedUsableForApplication()
            ? $profile->primaryCVFile
            : null;

        return ApiResponse::success([
            'current_cv' => $current === null ? null : new CurrentCVResource($current),
            'pending_cv_update' => null,
            'already_cancelled' => $result['already_cancelled'],
        ], __('cv.cancelled'));
    }

    public function confirm(ConfirmCVRequest $request, CVFile $cvFile): JsonResponse
    {
        $review = $this->cvService->confirm($request->user(), $cvFile);

        return ApiResponse::success(
            data: [
                'profile' => new JobSeekerProfileResource($review['profile']),
                'suggestions' => ProfileChangeSuggestionResource::collection($review['suggestions']),
                'current_cv' => new CurrentCVResource($review['cv']),
                'pending_cv_update' => null,
                'applied_changes' => $review['applied_changes'],
                'already_confirmed' => $review['already_confirmed'],
            ],
            message: __('cv.confirmed'),
        );
    }
}
