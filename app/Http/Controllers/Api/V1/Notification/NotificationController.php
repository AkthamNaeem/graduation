<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\IndexNotificationRequest;
use App\Http\Requests\Api\V1\Notification\MarkNotificationReadRequest;
use App\Http\Requests\Api\V1\Notification\UnreadCountNotificationRequest;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(IndexNotificationRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return ApiResponse::success(
            data: NotificationResource::collection(
                $this->notificationService->getUserNotifications(
                    $user,
                    $request->integer('per_page', 15),
                    $request->safe()->only(['is_read', 'type', 'date_from', 'date_to']),
                ),
            ),
            message: 'Notifications retrieved successfully.',
        );
    }

    public function unreadCount(UnreadCountNotificationRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return ApiResponse::success(
            data: [
                'unread_count' => $this->notificationService->unreadCount($user),
            ],
            message: 'Unread notification count retrieved successfully.',
        );
    }

    public function markAsRead(MarkNotificationReadRequest $request, Notification $notification): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return ApiResponse::success(
            data: new NotificationResource(
                $this->notificationService->markAsRead($notification, $user),
            ),
            message: 'Notification marked as read successfully.',
        );
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return ApiResponse::success(
            data: [
                'updated_count' => $this->notificationService->markAllAsRead($user),
            ],
            message: 'Notifications marked as read successfully.',
        );
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $this->notificationService->delete($notification, $user);

        return ApiResponse::success(
            data: null,
            message: 'Notification deleted successfully.',
        );
    }

    private function authenticatedUser(Request $request): User
    {
        $token = $request->bearerToken();
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        $tokenable = $accessToken?->tokenable;

        abort_unless($tokenable instanceof User, 401);

        return $tokenable->withAccessToken($accessToken);
    }
}
