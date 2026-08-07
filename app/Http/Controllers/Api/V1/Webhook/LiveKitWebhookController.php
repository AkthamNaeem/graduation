<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Services\LiveKitWebhookService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveKitWebhookController extends Controller
{
    public function __construct(
        private readonly LiveKitWebhookService $webhookService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->webhookService->process($request->getContent(), $request->header('Authorization'));

        return ApiResponse::success(null, __('interviews.livekit_webhook_received'));
    }
}
