<?php

namespace Database\Seeders;

use App\Enums\InterviewAttendanceStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use App\Enums\InterviewType;
use App\Models\Interview;
use App\Models\InterviewEvaluation;
use App\Models\InterviewEvaluationItem;
use App\Models\InterviewScheduleChange;
use App\Models\InterviewStatusHistory;
use App\Models\JobApplication;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoInterviewsSeeder extends Seeder
{
    public function run(): void
    {
        $now = DemoSeederContext::now();
        $scheduler = User::query()->where('email', 'employer.approved@workey.test')->firstOrFail();
        $interviewer = User::query()->where('email', 'employer.recruiter@workey.test')->firstOrFail();

        $this->interview(
            $this->application('interview_scheduled'),
            $scheduler,
            InterviewType::HR,
            InterviewStatus::SCHEDULED,
            InterviewMode::ONLINE,
            $now->copy()->addDays(2),
        );
        $this->interview(
            $this->application('interview_pending'),
            $scheduler,
            InterviewType::TECHNICAL,
            InterviewStatus::CONFIRMED,
            InterviewMode::ON_SITE,
            $now->copy()->addDays(3),
        );
        $this->interview(
            $this->application('on_hold'),
            $scheduler,
            InterviewType::FINAL,
            InterviewStatus::RESCHEDULED,
            InterviewMode::ONLINE,
            $now->copy()->addDays(5),
            rescheduled: true,
        );
        $this->interview(
            $this->application('interview_completed'),
            $scheduler,
            InterviewType::TECHNICAL,
            InterviewStatus::COMPLETED,
            InterviewMode::ON_SITE,
            $now->copy()->subDays(3),
        );
        $this->interview(
            $this->application('under_review'),
            $scheduler,
            InterviewType::HR,
            InterviewStatus::CANCELLED,
            InterviewMode::ONLINE,
            $now->copy()->subDay(),
        );
        $this->interview(
            $this->application('rejected'),
            $scheduler,
            InterviewType::HR,
            InterviewStatus::NO_SHOW,
            InterviewMode::ON_SITE,
            $now->copy()->subDays(4),
        );

        $finalReview = $this->application('final_review');
        foreach ([
            [InterviewType::HR, InterviewMode::ONLINE, 'advance'],
            [InterviewType::TECHNICAL, InterviewMode::ON_SITE, 'hold'],
            [InterviewType::FINAL, InterviewMode::ONLINE, 'reject'],
        ] as $index => [$type, $mode, $recommendation]) {
            $interview = $this->interview(
                $finalReview,
                $scheduler,
                $type,
                InterviewStatus::EVALUATED,
                $mode,
                $now->copy()->subDays(9 - ($index * 2)),
            );
            $this->evaluation($interview, $interviewer, $recommendation, $now->copy()->subDays(8 - ($index * 2)));
        }
    }

    private function interview(
        JobApplication $application,
        User $scheduler,
        InterviewType $type,
        InterviewStatus $status,
        InterviewMode $mode,
        Carbon $scheduledAt,
        bool $rescheduled = false,
    ): Interview {
        $duration = $type === InterviewType::TECHNICAL ? 90 : 45;
        $completed = in_array($status, [InterviewStatus::COMPLETED, InterviewStatus::EVALUATED], true);
        $cancelled = $status === InterviewStatus::CANCELLED;
        $noShow = $status === InterviewStatus::NO_SHOW;
        $confirmed = in_array($status, [InterviewStatus::CONFIRMED, InterviewStatus::COMPLETED, InterviewStatus::EVALUATED], true);

        $interview = Interview::query()->create([
            'job_application_id' => $application->id,
            'scheduled_by_user_id' => $scheduler->id,
            'interview_type' => $type->value,
            'status' => $status->value,
            'scheduled_at' => $scheduledAt,
            'scheduled_end_at' => $scheduledAt->copy()->addMinutes($duration),
            'duration_minutes' => $duration,
            'interview_mode' => $mode->value,
            'location' => $mode === InterviewMode::ON_SITE ? 'Workey Labs, Damascus' : null,
            'meeting_link' => $mode === InterviewMode::ONLINE ? 'https://meet.example.test/demo-'.$application->id.'-'.$type->value : null,
            'candidate_message' => 'Please join ten minutes early and have identification ready.',
            'internal_note' => 'Private interviewer preparation note for the employer team.',
            'note' => 'Private interviewer preparation note for the employer team.',
            'confirmed_at' => $confirmed ? $scheduledAt->copy()->subDays(2) : null,
            'confirmed_by_user_id' => $confirmed ? $application->jobSeekerProfile->user_id : null,
            'completion_note' => $completed ? 'Interview completed with all planned sections covered.' : null,
            'completed_at' => $completed ? $scheduledAt->copy()->addMinutes($duration) : null,
            'completed_by_user_id' => $completed ? $scheduler->id : null,
            'cancellation_reason' => $cancelled ? 'Hiring schedule changed before the interview.' : null,
            'cancellation_message' => $cancelled ? 'The interview was cancelled; a new invitation may follow.' : null,
            'cancelled_at' => $cancelled ? $scheduledAt->copy()->subDay() : null,
            'cancelled_by_user_id' => $cancelled ? $scheduler->id : null,
            'candidate_attendance_status' => match (true) {
                $noShow => InterviewAttendanceStatus::ABSENT->value,
                $cancelled => InterviewAttendanceStatus::EXCUSED->value,
                $completed => InterviewAttendanceStatus::PRESENT->value,
                default => InterviewAttendanceStatus::PENDING->value,
            },
            'interviewer_attendance_status' => $completed || $noShow
                ? InterviewAttendanceStatus::PRESENT->value
                : InterviewAttendanceStatus::PENDING->value,
            'attendance_recorded_at' => $completed || $noShow ? $scheduledAt->copy()->addMinutes($duration) : null,
            'attendance_recorded_by_user_id' => $completed || $noShow ? $scheduler->id : null,
            'attendance_note' => $noShow ? 'Candidate did not join within the grace period.' : ($completed ? 'Both parties attended.' : null),
            'created_at' => $scheduledAt->copy()->subDays(4),
            'updated_at' => $scheduledAt->copy()->addMinutes($duration),
        ]);

        $path = $this->statusPath($status);
        foreach ($path as $index => $toStatus) {
            InterviewStatusHistory::query()->create([
                'interview_id' => $interview->id,
                'from_status' => $index === 0 ? null : $path[$index - 1]->value,
                'to_status' => $toStatus->value,
                'changed_by_user_id' => $scheduler->id,
                'reason' => match ($toStatus) {
                    InterviewStatus::RESCHEDULED => 'Candidate requested a different time.',
                    InterviewStatus::CANCELLED => 'Hiring schedule changed.',
                    InterviewStatus::NO_SHOW => 'Attendance grace period elapsed.',
                    default => 'Demo interview lifecycle transition.',
                },
                'metadata' => ['demo' => true, 'type' => $type->value, 'mode' => $mode->value],
                'created_at' => $scheduledAt->copy()->subDays(count($path) - $index),
            ]);
        }

        if ($rescheduled) {
            InterviewScheduleChange::query()->create([
                'interview_id' => $interview->id,
                'previous_start_at' => $scheduledAt->copy()->subDay(),
                'previous_end_at' => $scheduledAt->copy()->subDay()->addMinutes($duration),
                'new_start_at' => $scheduledAt,
                'new_end_at' => $scheduledAt->copy()->addMinutes($duration),
                'previous_mode' => InterviewMode::ON_SITE->value,
                'new_mode' => $mode->value,
                'previous_meeting_link' => null,
                'new_meeting_link' => $interview->meeting_link,
                'previous_location_text' => 'Workey Labs, Damascus',
                'new_location_text' => $interview->location,
                'changed_by_user_id' => $scheduler->id,
                'reason' => 'Candidate requested an online interview on a later date.',
                'created_at' => $scheduledAt->copy()->subDays(2),
            ]);
        }

        return $interview;
    }

    /** @return list<InterviewStatus> */
    private function statusPath(InterviewStatus $status): array
    {
        return match ($status) {
            InterviewStatus::SCHEDULED => [InterviewStatus::SCHEDULED],
            InterviewStatus::CONFIRMED => [InterviewStatus::SCHEDULED, InterviewStatus::CONFIRMED],
            InterviewStatus::RESCHEDULED => [InterviewStatus::SCHEDULED, InterviewStatus::RESCHEDULED],
            InterviewStatus::COMPLETED => [InterviewStatus::SCHEDULED, InterviewStatus::CONFIRMED, InterviewStatus::COMPLETED],
            InterviewStatus::CANCELLED => [InterviewStatus::SCHEDULED, InterviewStatus::CANCELLED],
            InterviewStatus::NO_SHOW => [InterviewStatus::SCHEDULED, InterviewStatus::NO_SHOW],
            InterviewStatus::EVALUATED => [InterviewStatus::SCHEDULED, InterviewStatus::CONFIRMED, InterviewStatus::COMPLETED, InterviewStatus::EVALUATED],
        };
    }

    private function evaluation(Interview $interview, User $reviewer, string $recommendation, Carbon $evaluatedAt): void
    {
        $evaluation = InterviewEvaluation::query()->create([
            'interview_id' => $interview->id,
            'evaluated_by_user_id' => $reviewer->id,
            'recommendation' => $recommendation,
            'overall_comment' => "Demo {$recommendation} recommendation based on the recorded criteria.",
            'evaluated_at' => $evaluatedAt,
        ]);

        foreach ([
            ['Technical depth', 4, 'Explained trade-offs using concrete examples.'],
            ['Problem solving', 5, 'Structured the problem and validated assumptions.'],
            ['Communication', 4, 'Clear and concise throughout the interview.'],
        ] as $index => [$criterion, $score, $comment]) {
            InterviewEvaluationItem::query()->create([
                'interview_evaluation_id' => $evaluation->id,
                'criterion' => $criterion,
                'score' => $recommendation === 'reject' ? max(1, $score - 2) : $score,
                'comment' => $comment,
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function application(string $status): JobApplication
    {
        return JobApplication::query()
            ->with('jobSeekerProfile')
            ->whereHas('applicationStatus', fn ($query) => $query->where('slug', $status))
            ->firstOrFail();
    }
}
