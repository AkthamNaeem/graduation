<?php

namespace App\Http\Controllers\Api\V1\Application;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Application\GenerateCVSummaryRequest;
use App\Http\Requests\Api\V1\Application\ShowCVSummaryRequest;
use App\Http\Resources\Api\V1\ApplicationCVSummaryResource;
use App\Models\JobApplication;
use App\Services\CVSummary\ApplicationCVSummaryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ApplicationCVSummaryController extends Controller
{
    public function __construct(
        private readonly ApplicationCVSummaryService $summaryService,
    ) {}

    public function show(ShowCVSummaryRequest $request, JobApplication $jobApplication): JsonResponse
    {
        $summary = $this->summaryService->find($jobApplication, app()->getLocale());

        return ApiResponse::success(
            data: $summary === null ? null : new ApplicationCVSummaryResource($summary),
            message: __('cv_summary.retrieved'),
        );
    }

    public function generate(GenerateCVSummaryRequest $request, JobApplication $jobApplication): JsonResponse
    {
        $summary = $this->summaryService->generate(
            $jobApplication,
            $request->user('sanctum'),
            app()->getLocale(),
            $request->boolean('force'),
        );

        return ApiResponse::success(
            data: new ApplicationCVSummaryResource($summary),
            message: __('cv_summary.generated'),
        );
    }
}
