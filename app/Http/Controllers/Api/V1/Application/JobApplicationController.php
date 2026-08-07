<?php

namespace App\Http\Controllers\Api\V1\Application;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Application\ChangeApplicationStatusRequest;
use App\Http\Requests\Api\V1\Application\IndexJobApplicationsForJobRequest;
use App\Http\Requests\Api\V1\Application\MyJobApplicationIndexRequest;
use App\Http\Requests\Api\V1\Application\ShowJobApplicationRequest;
use App\Http\Requests\Api\V1\Application\StoreJobApplicationRequest;
use App\Http\Requests\Api\V1\Application\WithdrawJobApplicationRequest;
use App\Http\Resources\Api\V1\JobApplicationResource;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Services\ApplicationSnapshotCVAccessService;
use App\Services\ApplicationWorkflowService;
use App\Services\CV\CVDocumentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class JobApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationWorkflowService $applicationWorkflowService,
        private readonly ApplicationSnapshotCVAccessService $snapshotCVAccess,
        private readonly CVDocumentService $cvDocumentService,
    ) {}

    public function store(StoreJobApplicationRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->applyToJob($request->user('sanctum'), $jobPosting, $request->validated()),
            ),
            message: __('applications.created'),
            status: 201,
        );
    }

    public function my(MyJobApplicationIndexRequest $request): JsonResponse
    {
        $result = $this->applicationWorkflowService->getMyApplications(
            $request->user('sanctum'),
            $request->validated(),
        );

        return ApiResponse::success(
            data: JobApplicationResource::collection($result['applications'])->additional([
                'meta' => ['counts' => $result['counts']],
            ]),
            message: __('applications.list_retrieved'),
        );
    }

    public function show(ShowJobApplicationRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->getApplication($request->user('sanctum'), $jobApplication),
            ),
            message: __('applications.retrieved'),
        );
    }

    public function downloadCV(ShowJobApplicationRequest $request, JobApplication $jobApplication): Response
    {
        return $this->cvDocumentService->snapshotResponse(
            $this->snapshotCVAccess->snapshotFor($jobApplication),
            download: true,
        );
    }

    public function previewCV(ShowJobApplicationRequest $request, JobApplication $jobApplication): Response
    {
        return $this->cvDocumentService->snapshotResponse(
            $this->snapshotCVAccess->snapshotFor($jobApplication),
            download: false,
        );
    }

    public function withdraw(WithdrawJobApplicationRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->withdrawApplication(
                    $request->user('sanctum'),
                    $jobApplication,
                    $request->validated('note'),
                ),
            ),
            message: __('applications.withdrawn'),
        );
    }

    public function indexByJob(IndexJobApplicationsForJobRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: JobApplicationResource::collection(
                $this->applicationWorkflowService->getJobApplications(
                    $jobPosting,
                    $request->integer('per_page', 15),
                ),
            ),
            message: __('applications.list_retrieved'),
        );
    }

    public function changeStatus(ChangeApplicationStatusRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->changeStatus(
                    $request->user('sanctum'),
                    $jobApplication,
                    $request->validated('status'),
                    $request->validated('note'),
                ),
            ),
            message: __('applications.status_updated'),
        );
    }
}
