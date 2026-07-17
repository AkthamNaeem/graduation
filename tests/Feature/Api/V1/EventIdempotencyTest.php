<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Events\TestAssigned;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Notification;
use App\Models\Test as RecruitmentTest;
use App\Models\User;
use App\Services\EventEffectKeyFactory;
use App\Services\EventSideEffectService;
use App\Services\NotificationService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EventIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_duplicate_event_dispatch_creates_one_notification_and_one_ledger_row(): void
    {
        [$assignment, $candidate] = $this->assignedTestContext();
        $event = new TestAssigned($assignment->id);

        event($event);
        event($event);

        $this->assertSame(1, Notification::query()->where('user_id', $candidate->id)->where('type', 'test.assigned')->count());
        $this->assertDatabaseCount('event_side_effect_executions', 1);
        $this->assertDatabaseHas('event_side_effect_executions', [
            'effect_key' => "test.assigned:test_assignment:{$assignment->id}:notification:user:{$candidate->id}:v1",
            'event_name' => TestAssigned::class,
            'aggregate_type' => 'test_assignment',
            'aggregate_id' => (string) $assignment->id,
            'recipient_user_id' => $candidate->id,
        ]);
        $this->assertNotNull(Notification::query()->firstOrFail()->created_at);
    }

    public function test_execute_once_returns_false_for_duplicate_and_keeps_original_notification_unchanged(): void
    {
        $user = User::factory()->create();
        $sideEffects = app(EventSideEffectService::class);
        $notifications = app(NotificationService::class);
        $key = "example.created:example:10:notification:user:{$user->id}:v1";

        $first = $sideEffects->executeOnce($key, self::class, self::class, 'example', 10, $user->id, fn () => $notifications->createForUser($user, 'example.created', 'Original', 'Original message'));
        $createdAt = Notification::query()->firstOrFail()->created_at;
        $second = $sideEffects->executeOnce($key, self::class, self::class, 'example', 10, $user->id, fn () => $notifications->createForUser($user, 'example.created', 'Replacement', 'Replacement message'));

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('event_side_effect_executions', 1);
        $this->assertDatabaseHas('notifications', ['title' => 'Original', 'message' => 'Original message']);
        $this->assertSame($createdAt?->toISOString(), Notification::query()->firstOrFail()->created_at?->toISOString());
    }

    public function test_callback_failure_rolls_back_marker_and_effect_then_retry_succeeds(): void
    {
        $user = User::factory()->create();
        $sideEffects = app(EventSideEffectService::class);
        $notifications = app(NotificationService::class);
        $key = "example.retry:example:11:notification:user:{$user->id}:v1";

        try {
            $sideEffects->executeOnce($key, self::class, self::class, 'example', 11, $user->id, function () use ($notifications, $user): void {
                $notifications->createForUser($user, 'example.retry', 'Retry', 'First attempt');
                throw new RuntimeException('Simulated listener failure.');
            });
            $this->fail('The callback exception should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated listener failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('event_side_effect_executions', 0);

        $executed = $sideEffects->executeOnce($key, self::class, self::class, 'example', 11, $user->id, fn () => $notifications->createForUser($user, 'example.retry', 'Retry', 'Successful retry'));

        $this->assertTrue($executed);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('event_side_effect_executions', 1);
    }

    public function test_effect_keys_are_independent_per_recipient_and_occurrence(): void
    {
        $factory = app(EventEffectKeyFactory::class);

        $firstRecipient = $factory->notification('interview.updated', 'interview', 15, 40, 3);
        $secondRecipient = $factory->notification('interview.updated', 'interview', 15, 41, 3);
        $nextOccurrence = $factory->notification('interview.updated', 'interview', 15, 40, 4);

        $this->assertSame('interview.updated:interview:15:occurrence:3:notification:user:40:v1', $firstRecipient);
        $this->assertNotSame($firstRecipient, $secondRecipient);
        $this->assertNotSame($firstRecipient, $nextOccurrence);
    }

    /**
     * @return array{ApplicationTestAssignment, User}
     */
    private function assignedTestContext(): array
    {
        $company = Company::create(['name' => 'Idempotency Co.', 'approval_status' => 'approved']);
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $candidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $profile = JobSeekerProfile::create(['user_id' => $candidate->id, 'headline' => 'Candidate']);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Engineer',
            'description' => 'Build APIs.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Remote',
            'status' => 'open',
            'published_at' => now()->subHour(),
        ]);
        $status = ApplicationStatus::query()->where('slug', 'test_pending')->firstOrFail();
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $profile->id,
            'application_status_id' => $status->id,
        ]);
        $test = RecruitmentTest::forceCreate([
            'company_id' => $company->id,
            'title' => 'Assessment',
            'duration_minutes' => 30,
            'max_score' => 10,
            'passing_score' => 5,
            'is_active' => true,
        ]);
        $assignment = ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
            'assigned_at' => now(),
            'status' => 'assigned',
            'attempt_number' => 1,
            'max_attempts' => 1,
        ]);

        return [$assignment, $candidate];
    }
}
