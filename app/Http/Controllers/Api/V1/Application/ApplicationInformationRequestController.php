<?php

namespace App\Http\Controllers\Api\V1\Application;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Application\CancelApplicationInformationRequestRequest;
use App\Http\Requests\Api\V1\Application\DownloadApplicationInformationResponseAttachmentRequest;
use App\Http\Requests\Api\V1\Application\IndexApplicationInformationRequestRequest;
use App\Http\Requests\Api\V1\Application\ShowApplicationInformationRequestRequest;
use App\Http\Requests\Api\V1\Application\StoreApplicationInformationRequestRequest;
use App\Http\Requests\Api\V1\Application\SubmitApplicationInformationResponseRequest;
use App\Http\Requests\Api\V1\Application\UpdateApplicationInformationRequestRequest;
use App\Http\Resources\Api\V1\ApplicationInformationRequestResource;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationInformationResponseAttachment;
use App\Models\JobApplication;
use App\Services\ApplicationInformationRequestService;
use App\Services\PrivateFileStorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationInformationRequestController extends Controller
{
    public function __construct(
        private readonly ApplicationInformationRequestService $service,
        private readonly PrivateFileStorageService $privateStorage,
    ) {}

    public function index(IndexApplicationInformationRequestRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(ApplicationInformationRequestResource::collection($this->service->list($jobApplication)), 'Information requests retrieved successfully.');
    }

    public function store(StoreApplicationInformationRequestRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(new ApplicationInformationRequestResource($this->service->create($request->user('sanctum'), $jobApplication, $request->validated())), 'Information request created successfully.', 201);
    }

    public function show(ShowApplicationInformationRequestRequest $request, ApplicationInformationRequest $informationRequest): JsonResponse
    {
        return ApiResponse::success(new ApplicationInformationRequestResource($this->service->view($informationRequest)), 'Information request retrieved successfully.');
    }

    public function update(UpdateApplicationInformationRequestRequest $request, ApplicationInformationRequest $informationRequest): JsonResponse
    {
        return ApiResponse::success(new ApplicationInformationRequestResource($this->service->update($request->user('sanctum'), $informationRequest, $request->validated())), 'Information request updated successfully.');
    }

    public function respond(SubmitApplicationInformationResponseRequest $request, ApplicationInformationRequest $informationRequest): JsonResponse
    {
        return ApiResponse::success(new ApplicationInformationRequestResource($this->service->respond($request->user('sanctum'), $informationRequest, $request->validated('message'), $request->file('attachments', []))), 'Requested information submitted successfully.', 201);
    }

    public function cancel(CancelApplicationInformationRequestRequest $request, ApplicationInformationRequest $informationRequest): JsonResponse
    {
        return ApiResponse::success(new ApplicationInformationRequestResource($this->service->cancel($request->user('sanctum'), $informationRequest, $request->validated('reason'))), 'Information request cancelled successfully.');
    }

    public function download(DownloadApplicationInformationResponseAttachmentRequest $request, ApplicationInformationResponseAttachment $attachment): StreamedResponse
    {
        $attachment = $this->service->downloadableAttachment($attachment);

        return $this->privateStorage->downloadResponse($attachment->disk, $attachment->stored_path, $attachment->original_name, $attachment->mime_type);
    }
}
