<?php

namespace App\Http\Controllers\Api\V1\JobPosting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\JobPosting\AttachJobPostingSkillsRequest;
use App\Http\Requests\Api\V1\JobPosting\CloseJobPostingRequest;
use App\Http\Requests\Api\V1\JobPosting\DestroyJobPostingRequest;
use App\Http\Requests\Api\V1\JobPosting\DetachJobPostingSkillRequest;
use App\Http\Requests\Api\V1\JobPosting\IndexJobPostingRequest;
use App\Http\Requests\Api\V1\JobPosting\MyJobPostingIndexRequest;
use App\Http\Requests\Api\V1\JobPosting\PublishJobPostingRequest;
use App\Http\Requests\Api\V1\JobPosting\RankedCandidatesRequest;
use App\Http\Requests\Api\V1\JobPosting\RecommendedJobsRequest;
use App\Http\Requests\Api\V1\JobPosting\ShowJobPostingRequest;
use App\Http\Requests\Api\V1\JobPosting\StoreJobPostingRequest;
use App\Http\Requests\Api\V1\JobPosting\UpdateJobPostingRequest;
use App\Http\Resources\Api\V1\JobPostingResource;
use App\Http\Resources\Api\V1\RankedCandidateResource;
use App\Http\Resources\Api\V1\RecommendedJobResource;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Services\JobPostingService;
use App\Services\MatchingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class JobPostingController extends Controller
{
    public function __construct(
        private readonly JobPostingService $jobPostingService,
        private readonly MatchingService $matchingService,
    ) {
    }

    public function index(IndexJobPostingRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: JobPostingResource::collection($this->jobPostingService->getPublicJobs($request->validated())),
            message: 'Job postings retrieved successfully.',
        );
    }

    public function my(MyJobPostingIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: JobPostingResource::collection($this->jobPostingService->getEmployerJobs($request->user('sanctum'), $request->validated())),
            message: 'Employer job postings retrieved successfully.',
        );
    }

    public function recommended(RecommendedJobsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: RecommendedJobResource::collection(
                $this->matchingService->recommendJobsForUser(
                    $request->user('sanctum'),
                    $request->integer('limit', 10),
                ),
            ),
            message: 'Recommended jobs retrieved successfully.',
        );
    }

    public function show(ShowJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->getVisibleJobPosting($jobPosting)),
            message: 'Job posting retrieved successfully.',
        );
    }

    public function store(StoreJobPostingRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->createJob($request->user('sanctum'), $request->validated())),
            message: 'Job posting created successfully.',
            status: 201,
        );
    }

    public function update(UpdateJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->updateJob($jobPosting, $request->validated())),
            message: 'Job posting updated successfully.',
        );
    }

    public function destroy(DestroyJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        $this->jobPostingService->deleteJob($jobPosting);

        return ApiResponse::success(
            data: null,
            message: 'Job posting deleted successfully.',
        );
    }

    public function attachSkills(AttachJobPostingSkillsRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource(
                $this->jobPostingService->attachSkills($jobPosting, $request->validated('skill_ids')),
            ),
            message: 'Skills attached successfully.',
        );
    }

    public function detachSkill(DetachJobPostingSkillRequest $request, JobPosting $jobPosting, Skill $skill): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->detachSkills($jobPosting, $skill)),
            message: 'Skill detached successfully.',
        );
    }

    public function publish(PublishJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->publishJob($jobPosting)),
            message: 'Job posting published successfully.',
        );
    }

    public function close(CloseJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->closeJob($jobPosting)),
            message: 'Job posting closed successfully.',
        );
    }

    public function rankedCandidates(RankedCandidatesRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: RankedCandidateResource::collection(
                $this->matchingService->rankCandidatesForJob(
                    $jobPosting,
                    $request->integer('limit', 10),
                ),
            ),
            message: 'Ranked candidates retrieved successfully.',
        );
    }
}
