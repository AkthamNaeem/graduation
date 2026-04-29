<?php

namespace App\Http\Controllers\Api\V1\CV;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CV\ConfirmCVRequest;
use App\Http\Requests\Api\V1\CV\CVIndexRequest;
use App\Http\Requests\Api\V1\CV\ShowCVRequest;
use App\Http\Requests\Api\V1\CV\ShowParsedCVRequest;
use App\Http\Requests\Api\V1\CV\UploadCVRequest;
use App\Http\Resources\Api\V1\CVFileResource;
use App\Http\Resources\Api\V1\CVParsingResultResource;
use App\Http\Resources\Api\V1\JobSeekerProfileResource;
use App\Models\CVFile;
use App\Services\CVService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CVController extends Controller
{
    public function __construct(
        private readonly CVService $cvService,
    ) {
    }

    public function index(CVIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: CVFileResource::collection($this->cvService->list($request->user())),
            message: 'CV files retrieved successfully.',
        );
    }

    public function upload(UploadCVRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CVFileResource($this->cvService->upload($request->user(), $request->file('file'))),
            message: 'CV uploaded successfully. Parsing has been queued.',
            status: 201,
        );
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

    public function confirm(ConfirmCVRequest $request, CVFile $cvFile): JsonResponse
    {
        return ApiResponse::success(
            data: new JobSeekerProfileResource($this->cvService->confirm($request->user(), $cvFile)),
            message: 'CV parsing result confirmed successfully.',
        );
    }
}
