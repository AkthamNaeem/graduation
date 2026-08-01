<?php

namespace App\Services\Activity;

use App\Enums\ActivityActionType;
use App\Enums\ActivityType;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\TestAttempt;
use App\Services\CandidateActionResolver;
use App\Support\LocalizedValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ActivityActionResolver
{
    public function __construct(private readonly CandidateActionResolver $candidateActions) {}

    /** @return array<string, mixed> */
    public function test(ApplicationTestAssignment $assignment, ActivityDateRange $dates): array
    {
        /** @var TestAttempt|null $attempt */
        $state = $this->candidateActions->test($assignment);
        $attempt = $state['attempt'];
        $dueAt = $state['due_at'];
        $overdue = $state['is_overdue'];
        $actionType = $state['state'] === 'not_started'
            ? ActivityActionType::START_TEST
            : ActivityActionType::CONTINUE_TEST;

        return $this->item(
            sourceType: $attempt === null ? 'test_assignment' : 'test_attempt',
            sourceId: $attempt?->id ?? $assignment->id,
            type: ActivityType::TEST,
            title: $attempt === null ? __('activity.items.test_pending_title') : __('activity.items.test_continue_title'),
            description: __('activity.items.test_description'),
            application: $assignment->jobApplication,
            requiresAction: true,
            priority: $this->priority(true, $overdue, $dueAt, $attempt === null ? 600 : 700),
            action: $overdue ? null : $this->action($actionType, $attempt === null ? 'test_assignment' : 'test_attempt', $attempt?->id ?? $assignment->id),
            occurredAt: $attempt?->started_at ?? $assignment->assigned_at ?? $assignment->created_at,
            startsAt: null,
            dueAt: $dueAt,
            isOverdue: $overdue,
            dates: $dates,
        );
    }

    /** @return array<string, mixed> */
    public function interview(Interview $interview, ActivityDateRange $dates): array
    {
        $requiresAction = $this->candidateActions->interview($interview)['requires_action'];

        return $this->item(
            sourceType: 'interview',
            sourceId: $interview->id,
            type: ActivityType::INTERVIEW,
            title: __('activity.items.interview_title'),
            description: $interview->candidate_message,
            application: $interview->jobApplication,
            requiresAction: $requiresAction,
            priority: $requiresAction ? 800 : 400,
            action: $this->action(
                $requiresAction ? ActivityActionType::CONFIRM_INTERVIEW : ActivityActionType::VIEW_INTERVIEW,
                'interview',
                $interview->id,
            ),
            occurredAt: $interview->created_at,
            startsAt: $interview->scheduled_at,
            dueAt: null,
            isOverdue: false,
            dates: $dates,
        );
    }

    /** @return array<string, mixed> */
    public function informationRequest(ApplicationInformationRequest $informationRequest, ActivityDateRange $dates): array
    {
        $state = $this->candidateActions->informationRequest($informationRequest);
        $canRespond = $state['requires_action'];
        $overdue = $state['is_overdue'];

        return $this->item(
            sourceType: 'information_request',
            sourceId: $informationRequest->id,
            type: ActivityType::INFORMATION_REQUEST,
            title: __('activity.items.information_request_title'),
            description: $informationRequest->message,
            application: $informationRequest->jobApplication,
            requiresAction: $canRespond,
            priority: $this->priority($canRespond, $overdue, $informationRequest->due_at, 500),
            action: $canRespond
                ? $this->action(ActivityActionType::SUBMIT_INFORMATION, 'information_request', $informationRequest->id)
                : null,
            occurredAt: $informationRequest->created_at,
            startsAt: null,
            dueAt: $informationRequest->due_at,
            isOverdue: $overdue,
            dates: $dates,
        );
    }

    /** @return array<string, mixed> */
    public function notification(array $payload, ActivityDateRange $dates): array
    {
        $type = ActivityType::tryFrom((string) ($payload['activity_type'] ?? ''))
            ?? $this->notificationType((string) $payload['notification_type'], $payload);
        $actionType = ActivityActionType::tryFrom((string) ($payload['action_type'] ?? ''))
            ?? $this->fallbackNotificationAction($type);
        $target = $this->notificationTarget($payload);
        $occurredAt = $payload['occurred_at'] ?? $payload['created_at'];
        $startsAt = $payload['starts_at'] ?? $payload['scheduled_at'] ?? $payload['scheduled_start_at'] ?? null;
        $dueAt = $payload['due_at'] ?? $payload['deadline_at'] ?? null;

        return [
            'id' => 'notification:'.$payload['notification_id'],
            'activity_key' => $payload['activity_key'] ?? 'notification:'.$payload['notification_id'],
            'source' => ['type' => 'notification', 'id' => $payload['notification_id']],
            'type' => $this->localizedType($type),
            'title' => $payload['title'],
            'description' => $payload['message'],
            'application' => $payload['application'],
            'job' => $payload['job'],
            'company' => $payload['company'],
            'requires_action' => false,
            'priority' => $this->notificationPriority($type, (bool) ($payload['is_read'] ?? false)),
            'action' => $target === null || $actionType === ActivityActionType::NONE
                ? null
                : $this->action($actionType, $target['type'], $target['id']),
            'occurred_at' => Carbon::parse($occurredAt)->toISOString(),
            'starts_at' => $startsAt === null ? null : Carbon::parse($startsAt)->toISOString(),
            'due_at' => $dueAt === null ? null : Carbon::parse($dueAt)->toISOString(),
            'is_overdue' => false,
            'is_today' => $dates->isToday($occurredAt, $startsAt, $dueAt),
            'is_this_week' => $dates->isThisWeek($occurredAt, $startsAt, $dueAt),
            'notification_id' => $payload['notification_id'],
            'is_read' => $payload['is_read'],
            'read_at' => $payload['read_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function item(
        string $sourceType,
        int $sourceId,
        ActivityType $type,
        string $title,
        ?string $description,
        JobApplication $application,
        bool $requiresAction,
        int $priority,
        ?array $action,
        mixed $occurredAt,
        mixed $startsAt,
        mixed $dueAt,
        bool $isOverdue,
        ActivityDateRange $dates,
    ): array {
        $job = $application->jobPosting;
        $company = $job?->company;
        $status = $application->applicationStatus?->slug;

        return [
            'id' => "{$sourceType}:{$sourceId}",
            'activity_key' => "{$sourceType}:{$sourceId}",
            'source' => ['type' => $sourceType, 'id' => $sourceId],
            'type' => $this->localizedType($type),
            'title' => $title,
            'description' => $description,
            'application' => [
                'id' => $application->id,
                'status' => $status === null ? null : [
                    'key' => $status,
                    'label' => LocalizedValue::make($status, 'application_statuses')['value'],
                ],
            ],
            'job' => $job === null ? null : ['id' => $job->id, 'title' => $job->title],
            'company' => $company === null ? null : [
                'id' => $company->id,
                'name' => $company->name,
                'logo_url' => $company->logo_path === null ? null : Storage::disk('public')->url($company->logo_path),
            ],
            'requires_action' => $requiresAction,
            'priority' => $priority,
            'action' => $action,
            'occurred_at' => $occurredAt?->toISOString(),
            'starts_at' => $startsAt?->toISOString(),
            'due_at' => $dueAt?->toISOString(),
            'is_overdue' => $isOverdue,
            'is_today' => $dates->isToday($occurredAt, $startsAt, $dueAt),
            'is_this_week' => $dates->isThisWeek($occurredAt, $startsAt, $dueAt),
            'notification_id' => null,
            'is_read' => null,
            'read_at' => null,
        ];
    }

    /** @return array{type: array{key:string,label:string}, target: array{type:string,id:int}} */
    private function action(ActivityActionType $type, string $targetType, int $targetId): array
    {
        return [
            'type' => ['key' => $type->value, 'label' => __('activity.actions.'.$type->value)],
            'target' => ['type' => $targetType, 'id' => $targetId],
        ];
    }

    /** @return array{key:string,label:string} */
    private function localizedType(ActivityType $type): array
    {
        return ['key' => $type->value, 'label' => __('activity.types.'.$type->value)];
    }

    private function priority(bool $requiresAction, bool $overdue, mixed $dueAt, int $fallback): int
    {
        if ($requiresAction && $overdue) {
            return 1000;
        }
        if ($requiresAction && $dueAt !== null && now()->diffInHours($dueAt, false) <= 24) {
            return 900;
        }

        return $fallback;
    }

    private function notificationType(string $notificationType, array $payload): ActivityType
    {
        if (str_starts_with($notificationType, 'test.')) {
            return ActivityType::TEST;
        }
        if (str_starts_with($notificationType, 'interview.')) {
            return ActivityType::INTERVIEW;
        }
        if (str_contains($notificationType, 'information')) {
            return ActivityType::INFORMATION_REQUEST;
        }
        if (str_starts_with($notificationType, 'final.') || in_array($payload['status'] ?? null, ['accepted', 'rejected'], true)) {
            return ActivityType::FINAL_DECISION;
        }
        if (str_contains($notificationType, 'reminder')) {
            return ActivityType::APPLICATION_REMINDER;
        }

        return ActivityType::APPLICATION_STATUS;
    }

    private function fallbackNotificationAction(ActivityType $type): ActivityActionType
    {
        return match ($type) {
            ActivityType::TEST => ActivityActionType::VIEW_TEST_RESULT,
            ActivityType::INTERVIEW => ActivityActionType::VIEW_INTERVIEW,
            ActivityType::INFORMATION_REQUEST, ActivityType::APPLICATION_STATUS, ActivityType::FINAL_DECISION => ActivityActionType::VIEW_APPLICATION,
            default => ActivityActionType::NONE,
        };
    }

    /** @return array{type:string,id:int}|null */
    private function notificationTarget(array $payload): ?array
    {
        $type = $payload['resource_type'] ?? null;
        $id = $payload['resource_id'] ?? null;
        if (is_string($type) && is_numeric($id) && in_array($type, ['application', 'test_assignment', 'test_attempt', 'interview', 'information_request'], true)) {
            return ['type' => $type, 'id' => (int) $id];
        }

        foreach ([
            'test_attempt_id' => 'test_attempt',
            'test_assignment_id' => 'test_assignment',
            'interview_id' => 'interview',
            'information_request_id' => 'information_request',
            'application_id' => 'application',
            'job_application_id' => 'application',
        ] as $key => $targetType) {
            if (is_numeric($payload[$key] ?? null)) {
                return ['type' => $targetType, 'id' => (int) $payload[$key]];
            }
        }

        return null;
    }

    private function notificationPriority(ActivityType $type, bool $isRead): int
    {
        if ($isRead) {
            return 100;
        }

        return in_array($type, [ActivityType::FINAL_DECISION, ActivityType::APPLICATION_STATUS], true) ? 250 : 200;
    }
}
