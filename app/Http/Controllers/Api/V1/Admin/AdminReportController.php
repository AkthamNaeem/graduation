<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminReportRequest;
use App\Services\AdminReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminReportController extends Controller
{
    public function __construct(
        private readonly AdminReportService $adminReportService,
    ) {}

    public function overview(AdminReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->adminReportService->overview(),
            message: 'Admin overview report retrieved successfully.',
        );
    }

    public function applications(AdminReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->adminReportService->applications($request->validated()),
            message: 'Applications report retrieved successfully.',
        );
    }

    public function jobs(AdminReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->adminReportService->jobs($request->validated()),
            message: 'Jobs report retrieved successfully.',
        );
    }

    public function cvParsing(AdminReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->adminReportService->cvParsing($request->validated()),
            message: 'CV parsing report retrieved successfully.',
        );
    }
}
