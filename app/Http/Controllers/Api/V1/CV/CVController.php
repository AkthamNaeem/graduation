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
            message: 'CV files retrieved successfully.',
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
            message: 'CV uploaded successfully. Parsing has been queued.',
            status: 201,
        );
    }

    public function update(UpdateCVMetadataRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->updateLabel($request->user(), $cvFile, $request->validated('version_label'))), 'CV metadata updated successfully.');
    }

    public function makePrimary(CVLifecycleRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->makePrimary($request->user(), $cvFile)), 'Primary CV updated successfully.');
    }

    public function archive(ArchiveCVRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->archive($request->user(), $cvFile, $request->validated('replacement_cv_file_id'))), 'CV archived successfully.');
    }

    public function restore(CVLifecycleRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(new CVFileResource($this->cvService->restore($request->user(), $cvFile)), 'CV restored successfully.');
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
            message: 'CV file retrieved successfully.',
        );
    }

    public function parsed(ShowParsedCVRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            data: new CVParsingResultResource($this->cvService->getParsedResult($request->user(), $cvFile)),
            message: 'CV parsing result retrieved successfully.',
        );
    }

    public function review(ShowCVReviewRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            new CVReviewResource($this->cvService->getReview($request->user(), $cvFile)),
            'CV review retrieved successfully.',
        );
    }

    public function updateReviewDraft(UpdateCVReviewDraftRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            new CVReviewResource($this->cvService->updateReviewDraft($request->user(), $cvFile, $request->validated())),
            'CV review draft updated successfully.',
        );
    }

    public function confirm(ConfirmCVRequest $request, CVFile $cvFile): JsonResponse
    {
        $review = $this->cvService->confirm($request->user(), $cvFile);

        return ApiResponse::success(
            data: [
                'profile' => new JobSeekerProfileResource($review['profile']),
                'suggestions' => ProfileChangeSuggestionResource::collection($review['suggestions']),
            ],
            message: 'CV review suggestions are ready. Accept suggestions to apply parsed data.',
        );
    }
}
