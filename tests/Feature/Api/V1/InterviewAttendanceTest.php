<?php

namespace Tests\Feature\Api\V1;

use App\Models\Interview;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\V1\Concerns\CreatesInterviewScenarios;
use Tests\TestCase;

class InterviewAttendanceTest extends TestCase
{
    use CreatesInterviewScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_attendance_waits_for_start_is_idempotent_and_gates_completion(): void
    {
        [$employer, $candidate, $application] = $this->interviewScenario();
        $interviewId = $this->createInterview($employer, $application);
        $token = $this->tokenForInterviewUser($employer);
        $attendance = ['candidate_status' => 'present', 'interviewer_status' => 'present', 'note' => 'Both joined.'];

        $this->withToken($token)->putJson("/api/v1/interviews/{$interviewId}/attendance", $attendance)
            ->assertStatus(409)->assertJsonPath('code', 'INTERVIEW_ATTENDANCE_NOT_READY');
        $this->withToken($this->tokenForInterviewUser($candidate))->postJson("/api/v1/interviews/{$interviewId}/confirm")->assertOk();
        Carbon::setTestNow(Interview::findOrFail($interviewId)->scheduled_at);
        $this->withToken($token)->putJson("/api/v1/interviews/{$interviewId}/attendance", $attendance)->assertOk();
        $this->withToken($token)->putJson("/api/v1/interviews/{$interviewId}/attendance", $attendance)->assertOk();
        $this->assertDatabaseCount('audit_logs', 3);
        $this->withToken($token)->postJson("/api/v1/interviews/{$interviewId}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->withToken($token)->postJson("/api/v1/interviews/{$interviewId}/complete")
            ->assertStatus(409)->assertJsonPath('code', 'INTERVIEW_COMPLETION_NOT_ALLOWED');
    }

    public function test_absence_blocks_completion_and_no_show_is_terminal_without_rejecting_application(): void
    {
        [$employer, $candidate, $application] = $this->interviewScenario();
        $interviewId = $this->createInterview($employer, $application);
        $this->withToken($this->tokenForInterviewUser($candidate))->postJson("/api/v1/interviews/{$interviewId}/confirm")->assertOk();
        Carbon::setTestNow(Interview::findOrFail($interviewId)->scheduled_at);

        $this->withToken($this->tokenForInterviewUser($employer))->putJson("/api/v1/interviews/{$interviewId}/attendance", [
            'candidate_status' => 'absent', 'interviewer_status' => 'present', 'note' => 'Candidate did not join.',
        ])->assertOk();
        $this->withToken($this->tokenForInterviewUser($employer))->postJson("/api/v1/interviews/{$interviewId}/complete")
            ->assertStatus(409)->assertJsonPath('code', 'INTERVIEW_COMPLETION_NOT_ALLOWED');
        $this->withToken($this->tokenForInterviewUser($employer))->postJson("/api/v1/interviews/{$interviewId}/no-show", [
            'party' => 'candidate', 'reason' => 'Candidate did not attend.',
        ])->assertOk()->assertJsonPath('data.status', 'no_show')->assertJsonPath('data.candidate_attendance_status', 'absent');
        $this->assertNotSame('rejected', $application->fresh()->applicationStatus->slug);
        $this->withToken($this->tokenForInterviewUser($employer))->postJson("/api/v1/interviews/{$interviewId}/evaluate", [
            'recommendation' => 'reject', 'items' => [['criterion' => 'Attendance', 'score' => 1]],
        ])->assertStatus(409)->assertJsonPath('code', 'INTERVIEW_EVALUATION_NOT_ALLOWED');
    }

    public function test_no_show_supports_interviewer_and_both_parties(): void
    {
        foreach (['interviewer', 'both'] as $index => $party) {
            [$employer, , $application] = $this->interviewScenario('shortlisted', "-{$party}");
            $interviewId = $this->createInterview($employer, $application);
            Carbon::setTestNow(Interview::findOrFail($interviewId)->scheduled_at);
            $response = $this->withToken($this->tokenForInterviewUser($employer))->postJson("/api/v1/interviews/{$interviewId}/no-show", [
                'party' => $party, 'reason' => 'Recorded after start.',
            ])->assertOk();
            $response->assertJsonPath('data.interviewer_attendance_status', 'absent');
            $response->assertJsonPath('data.candidate_attendance_status', $party === 'both' ? 'absent' : 'present');
            Carbon::setTestNow();
        }
    }
}
