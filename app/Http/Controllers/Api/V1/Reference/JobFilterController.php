<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Services\JobFilterOptionsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class JobFilterController extends Controller
{
    public function __construct(
        private readonly JobFilterOptionsService $jobFilterOptionsService,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            data: $this->jobFilterOptionsService->getSchema(),
            message: __('job_filters.retrieved'),
        );
    }
}
