<?php

namespace App\Http\Controllers\Api\V1\JobPosting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\JobPosting\DestroyJobScreeningQuestionRequest;
use App\Http\Requests\Api\V1\JobPosting\IndexJobScreeningQuestionRequest;
use App\Http\Requests\Api\V1\JobPosting\StoreJobScreeningQuestionRequest;
use App\Http\Requests\Api\V1\JobPosting\UpdateJobScreeningQuestionRequest;
use App\Http\Resources\Api\V1\JobScreeningQuestionResource;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use App\Services\JobScreeningQuestionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class JobScreeningQuestionController extends Controller
{
    public function __construct(
        private readonly JobScreeningQuestionService $service,
    ) {}

    public function index(IndexJobScreeningQuestionRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: JobScreeningQuestionResource::collection($this->service->activeForJob($jobPosting)),
            message: 'Screening questions retrieved successfully.',
        );
    }

    public function store(StoreJobScreeningQuestionRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobScreeningQuestionResource(
                $this->service->create($request->user('sanctum'), $jobPosting, $request->validated()),
            ),
            message: 'Screening question created successfully.',
            status: 201,
        );
    }

    public function update(
        UpdateJobScreeningQuestionRequest $request,
        JobPosting $jobPosting,
        JobScreeningQuestion $question,
    ): JsonResponse {
        return ApiResponse::success(
            data: new JobScreeningQuestionResource(
                $this->service->update($request->user('sanctum'), $jobPosting, $question, $request->validated()),
            ),
            message: 'Screening question updated successfully.',
        );
    }

    public function destroy(
        DestroyJobScreeningQuestionRequest $request,
        JobPosting $jobPosting,
        JobScreeningQuestion $question,
    ): JsonResponse {
        $this->service->deactivate($request->user('sanctum'), $jobPosting, $question);

        return ApiResponse::success(message: 'Screening question disabled successfully.');
    }
}
