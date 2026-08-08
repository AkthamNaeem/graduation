<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationStatus;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Notification;
use App\Models\Test as RecruitmentTest;
use App\Models\TestAttempt;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivityPageTest extends TestCase
{
    use RefreshDatabase;

    private User $candidate;

    private User $employer;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        $this->company = Company::create([
            'name' => 'Activity Tech',
            'approval_status' => 'approved',
            'logo_path' => 'company-logos/activity.webp',
            'cover_image_path' => 'company-covers/activity.webp',
        ]);
        $this->candidate = $this->candidate('activity@example.com');
        $this->employer = User::factory()->create(['role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE]);
        EmployerProfile::create(['user_id' => $this->employer->id, 'company_id' => $this->company->id]);
    }

    public function test_activity_is_job_seeker_only_and_validates_filters(): void
    {
        $this->getJson('/api/v1/activity')->assertUnauthorized();
        $this->withToken($this->token($this->employer))->getJson('/api/v1/activity')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?group=invalid&type[]=unknown&sort_by=bad&sort_direction=sideways&timezone=Not/AZone&date_from=2026-08-02&date_to=2026-08-01&per_page=101&schedule_limit=21')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group', 'type.0', 'sort_by', 'sort_direction', 'timezone', 'date_to', 'per_page', 'schedule_limit']);
    }

    public function test_activity_aggregates_actions_schedule_feed_summary_and_privacy(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00 UTC');
        $application = $this->application('test_pending', 'Backend Developer');
        $test = RecruitmentTest::query()->forceCreate([
            'company_id' => $this->company->id,
            'title' => 'Backend Assessment',
            'duration_minutes' => 60,
            'max_score' => 100,
            'is_active' => true,
        ]);
        $assignment = ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $this->employer->id,
            'assigned_at' => now(),
            'deadline_at' => now()->addDay(),
        ]);
        $interview = Interview::create([
            'job_application_id' => $application->id,
            'scheduled_by_user_id' => $this->employer->id,
            'interview_type' => 'technical',
            'status' => 'scheduled',
            'scheduled_at' => now()->addHours(4),
            'scheduled_end_at' => now()->addHours(5),
            'interview_mode' => 'online',
            'internal_note' => 'Do not expose this evaluation note.',
        ]);
        $information = ApplicationInformationRequest::create([
            'job_application_id' => $application->id,
            'requested_by_user_id' => $this->employer->id,
            'message' => 'Please send your portfolio.',
            'due_at' => now()->addHours(20),
            'status' => 'pending',
            'previous_application_status' => 'under_review',
        ]);
        $notification = Notification::create([
            'user_id' => $this->candidate->id,
            'type' => 'application.status_changed',
            'title' => 'Application updated',
            'message' => 'Your application moved to review.',
            'data' => [
                'activity_version' => 1,
                'activity_key' => 'application_status_history:10',
                'application_id' => $application->id,
                'activity_type' => 'application_status',
                'action_type' => 'view_application',
                'resource_type' => 'application',
                'resource_id' => $application->id,
            ],
            'created_at' => now(),
        ]);

        $response = $this->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?timezone=Asia/Damascus')
            ->assertOk()
            ->assertJsonPath('data.feed.data.0.notification_id', $notification->id)
            ->assertJsonPath('data.feed.data.0.is_read', false)
            ->assertJsonPath('data.feed.meta.total', 1)
            ->assertJsonPath('data.summary.requires_action', 3)
            ->assertJsonPath('data.summary.unread_notifications', 1);

        $actionKeys = collect($response->json('data.requires_action'))->pluck('activity_key');
        $interviewItem = collect($response->json('data.requires_action'))->firstWhere('activity_key', 'interview:'.$interview->id);
        $expectedLogoUrl = Storage::disk('public')->url('company-logos/activity.webp');
        $expectedCoverUrl = Storage::disk('public')->url('company-covers/activity.webp');
        $this->assertSame('confirm_interview', $interviewItem['action']['type']['key']);
        foreach ($response->json('data.requires_action') as $item) {
            $this->assertSame($expectedLogoUrl, $item['company']['logo_url']);
            $this->assertSame($expectedCoverUrl, $item['company']['cover_image_url']);
        }
        foreach ($response->json('data.upcoming_schedule') as $item) {
            $this->assertSame($expectedLogoUrl, $item['company']['logo_url']);
            $this->assertSame($expectedCoverUrl, $item['company']['cover_image_url']);
        }
        $this->assertSame($expectedLogoUrl, $response->json('data.feed.data.0.company.logo_url'));
        $this->assertSame($expectedCoverUrl, $response->json('data.feed.data.0.company.cover_image_url'));
        $this->assertTrue($actionKeys->contains('test_assignment:'.$assignment->id));
        $this->assertTrue($actionKeys->contains('information_request:'.$information->id));
        $this->assertCount($actionKeys->count(), $actionKeys->unique());
        $this->assertStringNotContainsString('Do not expose', $response->getContent());
        $this->assertJson($response->getContent());
    }

    public function test_started_tests_filters_groups_localization_and_candidate_scoping(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00 UTC');
        $application = $this->application('test_pending', 'Platform Engineer');
        $assignment = $this->assignment($application, now()->addHours(3));
        $attempt = TestAttempt::create([
            'application_test_assignment_id' => $assignment->id,
            'started_at' => now()->subMinutes(5),
            'effective_deadline_at' => now()->addMinutes(55),
        ]);
        $other = $this->candidate('other-activity@example.com');
        Notification::query()->forceCreate([
            'user_id' => $other->id,
            'type' => 'test.assigned',
            'title' => 'Private test',
            'message' => 'Other candidate only',
            'created_at' => now(),
        ]);

        $this->withToken($this->token($this->candidate))
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/activity?group=today&type[]=test&search=Platform&timezone=Asia/Damascus')
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب النشاط بنجاح.')
            ->assertJsonPath('data.requires_action.0.source.type', 'test_attempt')
            ->assertJsonPath('data.requires_action.0.source.id', $attempt->id)
            ->assertJsonPath('data.requires_action.0.type.label', 'اختبار')
            ->assertJsonPath('data.requires_action.0.action.type.key', 'continue_test')
            ->assertJsonPath('data.feed.meta.total', 0)
            ->assertJsonMissing(['Other candidate only']);
    }

    public function test_requires_action_group_returns_no_historical_feed(): void
    {
        $application = $this->application('test_pending', 'Action Job');
        $this->assignment($application, now()->addDay());
        Notification::create([
            'user_id' => $this->candidate->id,
            'type' => 'test.assigned',
            'title' => 'Historical assignment',
            'message' => 'History',
            'created_at' => now(),
        ]);

        $this->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?group=requires_action')
            ->assertOk()
            ->assertJsonCount(1, 'data.requires_action')
            ->assertJsonCount(1, 'data.upcoming_schedule')
            ->assertJsonCount(0, 'data.feed.data')
            ->assertJsonPath('data.feed.meta.total', 0);
    }

    public function test_new_notifications_get_versioned_activity_payload_and_legacy_payloads_remain_safe(): void
    {
        $application = $this->application('under_review', 'Structured Job');
        $notification = app(NotificationService::class)->createForUser(
            $this->candidate,
            'application.status_changed',
            'Updated',
            'Application updated.',
            ['application_id' => $application->id, 'status' => 'under_review'],
        );

        $this->assertSame(1, $notification->data['activity_version']);
        $this->assertSame('application_status', $notification->data['activity_type']);
        $this->assertSame('application', $notification->data['resource_type']);
        $this->assertSame($application->id, $notification->data['resource_id']);
        $this->assertSame('Structured Job', $notification->data['job_title']);
        $this->assertSame('Activity Tech', $notification->data['company_name']);
        $this->assertSame('notification:'.$notification->id, $notification->data['activity_key']);

        Notification::create([
            'user_id' => $this->candidate->id,
            'type' => 'legacy.notice',
            'title' => 'Legacy notice',
            'message' => 'A safe old notification.',
            'data' => ['company_name' => 'Legacy Company'],
            'created_at' => now(),
        ]);
        app(NotificationService::class)->createForUser(
            $this->candidate,
            'application.information_requested',
            'Information needed',
            'Please provide more information.',
            ['application_id' => $application->id, 'information_request_id' => 99],
        );

        $response = $this->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity')
            ->assertOk()
            ->assertJsonPath('data.feed.meta.total', 3)
            ->assertJsonFragment(['title' => 'Legacy notice'])
            ->assertJsonMissingPath('data.feed.data.0.changed_by_user_id');

        $legacy = collect($response->json('data.feed.data'))->firstWhere('title', 'Legacy notice');
        $this->assertSame('Legacy Company', $legacy['company']['name']);
        $this->assertNull($legacy['company']['logo_url']);
        $this->assertNull($legacy['company']['cover_image_url']);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?type[]=information_request')
            ->assertOk()
            ->assertJsonPath('data.feed.meta.total', 1)
            ->assertJsonPath('data.feed.data.0.type.key', 'information_request');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?type[]=application_status')
            ->assertOk()
            ->assertJsonPath('data.feed.meta.total', 2)
            ->assertJsonMissing(['title' => 'Information needed']);
    }

    public function test_activity_query_count_is_bounded_as_feed_page_grows(): void
    {
        Notification::create([
            'user_id' => $this->candidate->id,
            'type' => 'application.status_changed',
            'title' => 'One',
            'message' => 'One',
            'created_at' => now(),
        ]);
        $token = $this->token($this->candidate);

        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/activity')->assertOk();
        $singleCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 15) as $number) {
            Notification::create([
                'user_id' => $this->candidate->id,
                'type' => 'application.status_changed',
                'title' => "Update {$number}",
                'message' => "Update {$number}",
                'created_at' => now()->addSeconds($number),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->flushHeaders()->withToken($token)->getJson('/api/v1/activity')->assertOk();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($singleCount + 1, $manyCount);
    }

    public function test_summary_counts_are_not_limited_by_action_or_feed_page_sizes(): void
    {
        $application = $this->application('need_more_information', 'Counted Job');
        foreach (range(1, 25) as $number) {
            ApplicationInformationRequest::create([
                'job_application_id' => $application->id,
                'requested_by_user_id' => $this->employer->id,
                'message' => "Request {$number}",
                'due_at' => now()->addDays($number),
                'status' => 'pending',
                'previous_application_status' => 'under_review',
            ]);
            Notification::create([
                'user_id' => $this->candidate->id,
                'type' => 'application.status_changed',
                'title' => "Update {$number}",
                'message' => "Update {$number}",
                'created_at' => now()->addSeconds($number),
            ]);
        }

        $this->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?per_page=5')
            ->assertOk()
            ->assertJsonCount(20, 'data.requires_action')
            ->assertJsonCount(5, 'data.feed.data')
            ->assertJsonPath('data.summary.requires_action', 25)
            ->assertJsonPath('data.summary.information_requests', 25)
            ->assertJsonPath('data.feed.meta.total', 25)
            ->assertJsonPath('data.summary.all', 50);
    }

    public function test_overdue_and_completed_entities_follow_action_and_schedule_rules(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00 UTC');
        $overdueApplication = $this->application('test_pending', 'Overdue Job');
        $overdue = $this->assignment($overdueApplication, now()->subMinute());
        $submittedApplication = $this->application('test_completed', 'Submitted Job');
        $submitted = $this->assignment($submittedApplication, now()->addDay());
        $submittedAttempt = TestAttempt::create([
            'application_test_assignment_id' => $submitted->id,
            'started_at' => now()->subHour(),
            'effective_deadline_at' => now()->addHour(),
            'submitted_at' => now(),
        ]);
        $cancelled = Interview::create([
            'job_application_id' => $overdueApplication->id,
            'scheduled_by_user_id' => $this->employer->id,
            'interview_type' => 'technical',
            'status' => 'cancelled',
            'scheduled_at' => now()->addHours(2),
            'scheduled_end_at' => now()->addHours(3),
            'interview_mode' => 'online',
        ]);

        $response = $this->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity')
            ->assertOk()
            ->assertJsonPath('data.requires_action.0.activity_key', 'test_assignment:'.$overdue->id)
            ->assertJsonPath('data.requires_action.0.is_overdue', true)
            ->assertJsonPath('data.requires_action.0.action', null);

        $content = $response->getContent();
        $this->assertStringNotContainsString('test_attempt:'.$submittedAttempt->id, $content);
        $this->assertStringNotContainsString('test_assignment:'.$submitted->id, $content);
        $this->assertStringNotContainsString('interview:'.$cancelled->id, $content);
        $this->assertSame([], $response->json('data.upcoming_schedule'));
    }

    public function test_date_range_filters_occurrences_and_due_dates_in_requested_timezone(): void
    {
        Carbon::setTestNow('2026-08-01 10:00:00 UTC');
        $application = $this->application('test_pending', 'Date Range Job');
        $assignment = $this->assignment($application, Carbon::parse('2026-08-05 12:00:00 UTC'));
        Notification::query()->forceCreate([
            'user_id' => $this->candidate->id,
            'type' => 'application.status_changed',
            'title' => 'Dated update',
            'message' => 'Inside range',
            'created_at' => Carbon::parse('2026-08-05 08:00:00 UTC'),
        ]);

        $this->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?date_from=2026-08-05&date_to=2026-08-05&timezone=Asia/Damascus')
            ->assertOk()
            ->assertJsonPath('data.requires_action.0.activity_key', 'test_assignment:'.$assignment->id)
            ->assertJsonCount(1, 'data.upcoming_schedule')
            ->assertJsonPath('data.feed.meta.total', 1);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($this->token($this->candidate))
            ->getJson('/api/v1/activity?date_from=2026-08-06&date_to=2026-08-06&timezone=Asia/Damascus')
            ->assertOk()
            ->assertJsonCount(0, 'data.requires_action')
            ->assertJsonCount(0, 'data.upcoming_schedule')
            ->assertJsonPath('data.feed.meta.total', 0);
    }

    private function application(string $status, string $title): JobApplication
    {
        $job = JobPosting::create([
            'company_id' => $this->company->id,
            'title' => $title,
            'description' => 'Description',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'location' => 'Damascus',
            'status' => 'open',
            'published_at' => now(),
        ]);

        return JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $this->candidate->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::query()->where('slug', $status)->value('id'),
        ]);
    }

    private function assignment(JobApplication $application, mixed $deadline): ApplicationTestAssignment
    {
        $test = RecruitmentTest::query()->forceCreate([
            'company_id' => $this->company->id,
            'title' => 'Assessment',
            'duration_minutes' => 60,
            'max_score' => 100,
            'is_active' => true,
        ]);

        return ApplicationTestAssignment::create([
            'job_application_id' => $application->id,
            'test_id' => $test->id,
            'assigned_by_user_id' => $this->employer->id,
            'assigned_at' => now(),
            'deadline_at' => $deadline,
        ]);
    }

    private function candidate(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE]);
        JobSeekerProfile::create(['user_id' => $user->id]);

        return $user->load('jobSeekerProfile');
    }

    private function token(User $user): string
    {
        return $user->createToken('activity-test')->plainTextToken;
    }
}
