<?php

namespace App\Http\Controllers\Api\V1\JobPosting;

use App\Contracts\Recommendation\RecommendationOrchestratorContract;
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
        private readonly RecommendationOrchestratorContract $recommendationOrchestrator,
    ) {}

    public function index(IndexJobPostingRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: JobPostingResource::collection($this->jobPostingService->getPublicJobs($request->validated())),
            message: __('jobs.list_retrieved'),
        );
    }

    public function my(MyJobPostingIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: JobPostingResource::collection($this->jobPostingService->getEmployerJobs($request->user('sanctum'), $request->validated())),
            message: __('jobs.employer_list_retrieved'),
        );
    }

    public function recommended(RecommendedJobsRequest $request): JsonResponse
    {
        $result = $this->recommendationOrchestrator->recommend(
            $request->user('sanctum'),
            $request->integer('limit', 10),
        );

        return ApiResponse::success(
            data: RecommendedJobResource::collection(
                $result->items,
            ),
            message: __('jobs.recommended'),
        );
    }

    public function show(ShowJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->getVisibleJobPosting($jobPosting)),
            message: __('jobs.retrieved'),
        );
    }

    public function store(StoreJobPostingRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->createJob($request->user('sanctum'), $request->validated())),
            message: __('jobs.created'),
            status: 201,
        );
    }

    public function update(UpdateJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->updateJob($request->user('sanctum'), $jobPosting, $request->validated())),
            message: __('jobs.updated'),
        );
    }

    public function destroy(DestroyJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        $this->jobPostingService->deleteJob($jobPosting);

        return ApiResponse::success(
            data: null,
            message: __('jobs.deleted'),
        );
    }

    public function attachSkills(AttachJobPostingSkillsRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource(
                $this->jobPostingService->attachSkills($request->user('sanctum'), $jobPosting, $request->validated()),
            ),
            message: __('jobs.skills_attached'),
        );
    }

    public function detachSkill(DetachJobPostingSkillRequest $request, JobPosting $jobPosting, Skill $skill): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->detachSkills($request->user('sanctum'), $jobPosting, $skill)),
            message: __('jobs.skill_detached'),
        );
    }

    public function publish(PublishJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->publishJob($request->user('sanctum'), $jobPosting)),
            message: __('jobs.published'),
        );
    }

    public function close(CloseJobPostingRequest $request, JobPosting $jobPosting): JsonResponse
    {
        return ApiResponse::success(
            data: new JobPostingResource($this->jobPostingService->closeJob($request->user('sanctum'), $jobPosting)),
            message: __('jobs.closed'),
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
            message: __('jobs.ranked_candidates'),
        );
    }
}
