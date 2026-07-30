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
            message: __('admin.overview_report'),
        );
    }

    public function applications(AdminReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->adminReportService->applications($request->validated()),
            message: __('admin.applications_report'),
        );
    }

    public function jobs(AdminReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->adminReportService->jobs($request->validated()),
            message: __('admin.jobs_report'),
        );
    }

    public function cvParsing(AdminReportRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->adminReportService->cvParsing($request->validated()),
            message: __('admin.cv_report'),
        );
    }
}
