<?php

namespace App\Http\Controllers\Api\V1\Application;

use App\Exceptions\CVLifecycleException;
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
use App\Services\ApplicationWorkflowService;
use App\Services\PrivateFileStorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationWorkflowService $applicationWorkflowService,
        private readonly PrivateFileStorageService $privateStorage,
    ) {}

    public function store(StoreJobApplicationRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->applyToJob($request->user('sanctum'), $jobPosting, $request->validated()),
            ),
            message: 'Job application created successfully.',
            status: 201,
        );
    }

    public function my(MyJobApplicationIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: JobApplicationResource::collection(
                $this->applicationWorkflowService->getMyApplications(
                    $request->user('sanctum'),
                    $request->integer('per_page', 15),
                ),
            ),
            message: 'Job applications retrieved successfully.',
        );
    }

    public function show(ShowJobApplicationRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->getApplication($request->user('sanctum'), $jobApplication),
            ),
            message: 'Job application retrieved successfully.',
        );
    }

    public function downloadCV(ShowJobApplicationRequest $request, JobApplication $jobApplication): StreamedResponse
    {
        $cvFile = $jobApplication->selectedCvFile()->first();
        if ($cvFile === null || ! $this->privateStorage->exists($cvFile->disk, $cvFile->stored_path)) {
            throw new CVLifecycleException('The selected CV file is unavailable.', 'CV_FILE_UNAVAILABLE', 404);
        }

        return $this->privateStorage->downloadResponse($cvFile->disk, $cvFile->stored_path, $cvFile->original_name, $cvFile->mime_type);
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
            message: 'Job application withdrawn successfully.',
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
            message: 'Job applications retrieved successfully.',
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
            message: 'Application status updated successfully.',
        );
    }
}
