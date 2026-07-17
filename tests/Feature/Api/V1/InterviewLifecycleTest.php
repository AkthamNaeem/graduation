<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApplicationStatus;
use App\Models\Interview;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\V1\Concerns\CreatesInterviewScenarios;
use Tests\TestCase;

class InterviewLifecycleTest extends TestCase
{
    use CreatesInterviewScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_only_the_candidate_owner_can_confirm_and_reschedule_requires_reconfirmation(): void
    {
        [$employer, $candidate, $application] = $this->interviewScenario();
        $interviewId = $this->createInterview($employer, $application);
        [, $otherCandidate] = $this->interviewScenario('shortlisted', '-other');

        $this->withToken($this->tokenForInterviewUser($otherCandidate))->postJson("/api/v1/interviews/{$interviewId}/confirm")->assertForbidden();
        $this->withToken($this->tokenForInterviewUser($employer))->postJson("/api/v1/interviews/{$interviewId}/confirm")->assertForbidden();
        $this->withToken($this->tokenForInterviewUser($candidate))
            ->postJson("/api/v1/interviews/{$interviewId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/reschedule", [
                'mode' => 'online',
                'scheduled_start_at' => now()->addDays(2)->toISOString(),
                'scheduled_end_at' => now()->addDays(2)->addHour()->toISOString(),
                'meeting_link' => 'https://meet.example.com/new-time',
                'reason' => 'Interviewer conflict.',
            ])->assertOk()->assertJsonPath('data.candidate_confirmation_status', 'pending');

        $this->assertDatabaseHas('interviews', ['id' => $interviewId, 'status' => 'rescheduled', 'confirmed_at' => null, 'confirmed_by_user_id' => null]);
        $this->withToken($this->tokenForInterviewUser($candidate))->postJson("/api/v1/interviews/{$interviewId}/confirm")->assertOk();
        $this->assertSame(['scheduled', 'confirmed', 'rescheduled', 'confirmed'], Interview::findOrFail($interviewId)->statusHistory()->pluck('to_status')->all());

        $this->withToken($this->tokenForInterviewUser($employer))
            ->getJson("/api/v1/interviews/{$interviewId}/status-history?per_page=10")
            ->assertOk()
            ->assertJsonCount(4, 'data.data')
            ->assertJsonPath('data.data.0.to_status', 'scheduled')
            ->assertJsonPath('data.meta.total', 4);
        $this->withToken($this->tokenForInterviewUser($employer))
            ->getJson("/api/v1/interviews/{$interviewId}/schedule-history")
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.reason', 'Interviewer conflict.');
        $this->withToken($this->tokenForInterviewUser($candidate))
            ->getJson("/api/v1/interviews/{$interviewId}/status-history")
            ->assertForbidden();
    }

    public function test_cancellation_is_stateful_private_and_recalculates_application_status(): void
    {
        [$employer, $candidate, $application] = $this->interviewScenario();
        $interviewId = $this->createInterview($employer, $application);

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/cancel", [
                'reason' => 'Internal staffing decision.',
                'candidate_message' => 'We need to cancel this interview.',
            ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('interviews', ['id' => $interviewId, 'status' => 'cancelled', 'cancellation_reason' => 'Internal staffing decision.', 'cancelled_by_user_id' => $employer->id]);
        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'application_status_id' => ApplicationStatus::query()->where('slug', 'interview_pending')->value('id')]);
        $this->withToken($this->tokenForInterviewUser($candidate))
            ->getJson("/api/v1/interviews/{$interviewId}")
            ->assertOk()
            ->assertJsonPath('data.cancellation_message', 'We need to cancel this interview.')
            ->assertJsonMissingPath('data.cancellation_reason')
            ->assertJsonMissingPath('data.cancelled_by_user_id');
        $this->withToken($this->tokenForInterviewUser($candidate))
            ->postJson("/api/v1/interviews/{$interviewId}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_CONFIRMATION_NOT_ALLOWED');
    }

    public function test_completion_and_evaluation_follow_type_specific_application_policy(): void
    {
        [$employer, $candidate, $application] = $this->interviewScenario('shortlisted', '-final');
        $interviewId = $this->createInterview($employer, $application, ['type' => 'final']);
        $this->withToken($this->tokenForInterviewUser($candidate))->postJson("/api/v1/interviews/{$interviewId}/confirm")->assertOk();
        Carbon::setTestNow(Interview::findOrFail($interviewId)->scheduled_at);
        $this->withToken($this->tokenForInterviewUser($employer))->putJson("/api/v1/interviews/{$interviewId}/attendance", [
            'candidate_status' => 'present', 'interviewer_status' => 'present',
        ])->assertOk();
        $this->withToken($this->tokenForInterviewUser($employer))->postJson("/api/v1/interviews/{$interviewId}/complete", [
            'completion_note' => 'Round complete.',
        ])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->withToken($this->tokenForInterviewUser($employer))->postJson("/api/v1/interviews/{$interviewId}/evaluate", [
            'recommendation' => 'advance',
            'overall_comment' => 'Strong final discussion.',
            'items' => [['criterion' => 'Communication', 'score' => 5]],
        ])->assertOk()->assertJsonPath('data.status', 'evaluated')->assertJsonPath('data.job_application.status.slug', 'final_review');

        $this->assertSame(['scheduled', 'confirmed', 'completed', 'evaluated'], Interview::findOrFail($interviewId)->statusHistory()->pluck('to_status')->all());
    }
}
