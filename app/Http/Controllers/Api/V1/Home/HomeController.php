<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Http\Controllers\Controller;
use App\Services\Home\HomeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(
        private readonly HomeService $homeService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: $this->homeService->get($request->user('sanctum')),
            message: 'Home data retrieved successfully.',
        );
    }
}
