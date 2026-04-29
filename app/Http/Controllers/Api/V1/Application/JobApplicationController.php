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
use App\Services\ApplicationWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class JobApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationWorkflowService $applicationWorkflowService,
    ) {
    }

    public function store(StoreJobApplicationRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->applyToJob($request->user('sanctum'), $jobPosting),
            ),
            message: 'Job application created successfully.',
            status: 201,
        );
    }

    public function my(MyJobApplicationIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: JobApplicationResource::collection(
                $this->applicationWorkflowService->getMyApplications($request->user('sanctum')),
            ),
            message: 'Job applications retrieved successfully.',
        );
    }

    public function show(ShowJobApplicationRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new JobApplicationResource(
                $this->applicationWorkflowService->getApplication($jobApplication),
            ),
            message: 'Job application retrieved successfully.',
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
            message: 'Job application withdrawn successfully.',
        );
    }

    public function indexByJob(IndexJobApplicationsForJobRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: JobApplicationResource::collection(
                $this->applicationWorkflowService->getJobApplications($jobPosting),
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
