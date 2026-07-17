<?php

namespace Tests\Feature\Api\V1;

use App\Events\ApplicationInformationRequestCancelled;
use App\Events\ApplicationInformationRequested;
use App\Events\ApplicationInformationRequestUpdated;
use App\Events\ApplicationInformationResponded;
use App\Events\ApplicationStatusChanged;
use App\Events\ApplicationSubmitted;
use App\Events\InterviewAttendanceUpdated;
use App\Events\InterviewCancelled;
use App\Events\InterviewCompleted;
use App\Events\InterviewConfirmed;
use App\Events\InterviewEvaluated;
use App\Events\InterviewNoShow;
use App\Events\InterviewRescheduled;
use App\Events\InterviewScheduled;
use App\Events\InterviewUpdated;
use App\Events\TestAssigned;
use App\Events\TestAssignmentDeadlineExtended;
use App\Events\TestEvaluated;
use App\Events\TestRetakeGranted;
use App\Events\TestSubmitted;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventRegistrationTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function criticalEvents(): array
    {
        return [
            'application information request cancelled' => [ApplicationInformationRequestCancelled::class],
            'application information request updated' => [ApplicationInformationRequestUpdated::class],
            'application information requested' => [ApplicationInformationRequested::class],
            'application information responded' => [ApplicationInformationResponded::class],
            'application status changed' => [ApplicationStatusChanged::class],
            'application submitted' => [ApplicationSubmitted::class],
            'interview cancelled' => [InterviewCancelled::class],
            'interview attendance updated' => [InterviewAttendanceUpdated::class],
            'interview completed' => [InterviewCompleted::class],
            'interview confirmed' => [InterviewConfirmed::class],
            'interview evaluated' => [InterviewEvaluated::class],
            'interview no show' => [InterviewNoShow::class],
            'interview rescheduled' => [InterviewRescheduled::class],
            'interview scheduled' => [InterviewScheduled::class],
            'interview updated' => [InterviewUpdated::class],
            'test assigned' => [TestAssigned::class],
            'test deadline extended' => [TestAssignmentDeadlineExtended::class],
            'test evaluated' => [TestEvaluated::class],
            'test retake granted' => [TestRetakeGranted::class],
            'test submitted' => [TestSubmitted::class],
        ];
    }

    #[DataProvider('criticalEvents')]
    public function test_each_critical_event_has_exactly_one_discovered_listener(string $event): void
    {
        $this->assertCount(1, Event::getListeners($event));
    }

    public function test_app_service_provider_does_not_manually_register_discovered_events(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertIsString($provider);
        $this->assertStringNotContainsString('Event::listen', $provider);
    }
}
