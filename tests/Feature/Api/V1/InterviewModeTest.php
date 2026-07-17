<?php

namespace Tests\Feature\Api\V1;

use App\Models\Interview;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\CreatesInterviewScenarios;
use Tests\TestCase;

class InterviewModeTest extends TestCase
{
    use CreatesInterviewScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
    }

    public function test_online_and_on_site_configuration_is_strict_and_irrelevant_fields_are_cleared(): void
    {
        [$employer, , $application] = $this->interviewScenario();

        $onlineId = $this->createInterview($employer, $application, ['location_text' => 'Must be cleared']);
        $this->assertDatabaseHas('interviews', ['id' => $onlineId, 'interview_mode' => 'online', 'location' => null]);

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->validInterviewPayload([
                'type' => 'hr',
                'meeting_link' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTERVIEW_MODE_CONFIGURATION_INVALID');

        $onSiteId = $this->createInterview($employer, $application, [
            'type' => 'hr',
            'mode' => 'on_site',
            'location_text' => 'Damascus HQ',
            'meeting_link' => 'https://meet.example.com/must-be-cleared',
        ]);
        $this->assertDatabaseHas('interviews', ['id' => $onSiteId, 'interview_mode' => 'on_site', 'location' => 'Damascus HQ', 'meeting_link' => null]);

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->validInterviewPayload([
                'type' => 'final',
                'mode' => 'on_site',
                'meeting_link' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTERVIEW_MODE_CONFIGURATION_INVALID');
    }

    public function test_time_bounds_and_active_type_uniqueness_are_enforced(): void
    {
        [$employer, , $application] = $this->interviewScenario();
        $this->createInterview($employer, $application);

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->validInterviewPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'INTERVIEW_ALREADY_ACTIVE_FOR_TYPE');

        $this->createInterview($employer, $application, ['type' => 'hr']);

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->validInterviewPayload([
                'type' => 'final',
                'scheduled_end_at' => now()->addDay()->addHours(9)->toISOString(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTERVIEW_TIME_INVALID');
    }

    public function test_reschedule_validates_the_final_mode_configuration_and_preserves_old_schedule(): void
    {
        [$employer, , $application] = $this->interviewScenario();
        $interviewId = $this->createInterview($employer, $application);

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/reschedule", [
                'mode' => 'on_site',
                'scheduled_start_at' => now()->addDays(2)->toISOString(),
                'scheduled_end_at' => now()->addDays(2)->addMinutes(90)->toISOString(),
                'location_text' => 'Aleppo Office',
                'meeting_link' => 'https://meet.example.com/old',
                'reason' => 'Panel availability changed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rescheduled')
            ->assertJsonPath('data.meeting_link', null)
            ->assertJsonPath('data.location_text', 'Aleppo Office');

        $this->assertDatabaseCount('interview_schedule_changes', 1);
        $this->assertSame('online', Interview::findOrFail($interviewId)->scheduleChanges()->firstOrFail()->previous_mode);
    }
}
