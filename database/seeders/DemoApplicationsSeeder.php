<?php

namespace Database\Seeders;

use App\Enums\ScreeningQuestionType;
use App\Models\ApplicationStatus;
use App\Models\ApplicationStatusHistory;
use App\Models\CVFile;
use App\Models\JobApplication;
use App\Models\JobApplicationScreeningAnswer;
use App\Models\JobApplicationScreeningAnswerOption;
use App\Models\JobApplicationScreeningQuestion;
use App\Models\JobApplicationScreeningQuestionOption;
use App\Models\JobPosting;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;

class DemoApplicationsSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    public const PATHS = [
        'submitted' => ['submitted'],
        'under_review' => ['submitted', 'under_review'],
        'shortlisted' => ['submitted', 'under_review', 'shortlisted'],
        'test_pending' => ['submitted', 'under_review', 'shortlisted', 'test_pending'],
        'test_completed' => ['submitted', 'under_review', 'shortlisted', 'test_pending', 'test_completed'],
        'interview_pending' => ['submitted', 'under_review', 'shortlisted', 'test_pending', 'test_completed', 'interview_pending'],
        'interview_scheduled' => ['submitted', 'under_review', 'shortlisted', 'interview_scheduled'],
        'interview_completed' => ['submitted', 'under_review', 'shortlisted', 'interview_scheduled', 'interview_completed'],
        'final_review' => ['submitted', 'under_review', 'shortlisted', 'interview_scheduled', 'interview_completed', 'final_review'],
        'accepted' => ['submitted', 'under_review', 'shortlisted', 'test_pending', 'test_completed', 'interview_pending', 'interview_scheduled', 'interview_completed', 'final_review', 'accepted'],
        'rejected' => ['submitted', 'under_review', 'rejected'],
        'withdrawn' => ['submitted', 'withdrawn'],
        'on_hold' => ['submitted', 'under_review', 'on_hold'],
        'need_more_information' => ['submitted', 'under_review', 'need_more_information'],
    ];

    /** @var array<string, array{string,string}> */
    private const SCENARIOS = [
        'submitted' => ['seeker.junior@workey.test', 'AI Research Intern'],
        'under_review' => ['seeker.frontend@workey.test', 'Frontend Product Engineer'],
        'shortlisted' => ['seeker.senior@workey.test', 'Senior Laravel Backend Engineer'],
        'test_pending' => ['seeker.backend@workey.test', 'Part-time API Support Engineer'],
        'test_completed' => ['seeker.data@workey.test', 'Machine Learning Engineer'],
        'interview_pending' => ['seeker.frontend@workey.test', 'Part-time API Support Engineer'],
        'interview_scheduled' => ['seeker.junior@workey.test', 'Senior Laravel Backend Engineer'],
        'interview_completed' => ['seeker.senior@workey.test', 'Technical Recruiter'],
        'final_review' => ['seeker.data@workey.test', 'Senior Laravel Backend Engineer'],
        'accepted' => ['seeker.backend@workey.test', 'Senior Laravel Backend Engineer'],
        'rejected' => ['seeker.incomplete@workey.test', 'Frontend Product Engineer'],
        'withdrawn' => ['seeker.suspended@workey.test', 'AI Research Intern'],
        'on_hold' => ['seeker.frontend@workey.test', 'Machine Learning Engineer'],
        'need_more_information' => ['seeker.junior@workey.test', 'Frontend Product Engineer'],
    ];

    public function run(): void
    {
        $now = DemoSeederContext::now();
        $employer = User::query()->where('email', 'employer.approved@workey.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@workey.test')->firstOrFail();
        $statuses = ApplicationStatus::query()->get()->keyBy('slug');

        foreach (self::SCENARIOS as $currentStatus => [$email, $jobTitle]) {
            $candidate = User::query()->with('jobSeekerProfile')->where('email', $email)->firstOrFail();
            $job = JobPosting::query()->where('title', $jobTitle)->firstOrFail();
            $path = self::PATHS[$currentStatus];
            $submittedAt = $now->copy()->subDays(18)->addMinutes(array_search($currentStatus, array_keys(self::SCENARIOS), true) * 5);
            $selectedCvId = CVFile::query()
                ->where('user_id', $candidate->id)
                ->whereNull('archived_at')
                ->orderByRaw("case when status = 'parsed' then 0 else 1 end")
                ->value('id');

            $application = JobApplication::query()->create([
                'job_posting_id' => $job->id,
                'job_seeker_profile_id' => $candidate->jobSeekerProfile->id,
                'selected_cv_file_id' => $selectedCvId,
                'application_status_id' => $statuses[$currentStatus]->id,
                'cover_letter' => "Demo cover letter for {$jobTitle}; current workflow state is {$currentStatus}.",
                'consent_to_share_profile' => true,
                'screening_answers' => null,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt->copy()->addHours(count($path) * 8),
            ]);

            $from = null;
            foreach ($path as $index => $to) {
                $actor = match ($to) {
                    'submitted', 'withdrawn' => $candidate,
                    default => $to === 'test_completed' ? $admin : $employer,
                };
                ApplicationStatusHistory::query()->create([
                    'job_application_id' => $application->id,
                    'from_application_status_id' => $from === null ? null : $statuses[$from]->id,
                    'to_application_status_id' => $statuses[$to]->id,
                    'changed_by_user_id' => $actor->id,
                    'note' => $this->historyNote($to),
                    'created_at' => $submittedAt->copy()->addHours($index * 8),
                    'updated_at' => $submittedAt->copy()->addHours($index * 8),
                ]);
                $from = $to;
            }
        }

        $this->screeningSnapshots(
            JobApplication::query()
                ->whereHas('jobPosting', fn ($query) => $query->where('title', 'Senior Laravel Backend Engineer'))
                ->whereHas('jobSeekerProfile.user', fn ($query) => $query->where('email', 'seeker.backend@workey.test'))
                ->firstOrFail(),
        );
    }

    private function historyNote(string $status): string
    {
        return match ($status) {
            'submitted' => 'Candidate submitted a complete application and sharing consent.',
            'withdrawn' => 'Candidate withdrew after changing availability.',
            'rejected' => 'Employer recorded a skills-alignment decision.',
            'on_hold' => 'Hiring plan paused while headcount is reviewed.',
            'need_more_information' => 'Employer requested supporting project details.',
            default => 'Demo workflow transition to '.str($status)->replace('_', ' ')->toString().'.',
        };
    }

    private function screeningSnapshots(JobApplication $application): void
    {
        $questions = $application->jobPosting->screeningQuestions()->with('options')->get();

        foreach ($questions as $question) {
            $snapshot = JobApplicationScreeningQuestion::query()->create([
                'job_application_id' => $application->id,
                'source_question_id' => $question->id,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'is_required' => $question->is_required,
                'sort_order' => $question->sort_order,
            ]);
            $optionMap = [];
            foreach ($question->options as $option) {
                $snapshotOption = JobApplicationScreeningQuestionOption::query()->create([
                    'application_question_id' => $snapshot->id,
                    'source_option_id' => $option->id,
                    'option_text' => $option->option_text,
                    'sort_order' => $option->sort_order,
                ]);
                $optionMap[] = $snapshotOption;
            }

            $answer = JobApplicationScreeningAnswer::query()->create([
                'job_application_id' => $application->id,
                'application_question_id' => $snapshot->id,
                'text_value' => match ($question->question_type) {
                    ScreeningQuestionType::SHORT_TEXT => 'A multi-tenant recruitment API built with Laravel.',
                    ScreeningQuestionType::LONG_TEXT => 'I use explicit contracts, database constraints, idempotency, monitoring, and automated integration tests.',
                    default => null,
                },
                'number_value' => $question->question_type === ScreeningQuestionType::NUMBER ? 5 : null,
                'boolean_value' => $question->question_type === ScreeningQuestionType::BOOLEAN ? true : null,
            ]);

            $selected = match ($question->question_type) {
                ScreeningQuestionType::SINGLE_CHOICE => array_slice($optionMap, 0, 1),
                ScreeningQuestionType::MULTIPLE_CHOICE => array_slice($optionMap, 0, 2),
                default => [],
            };
            foreach ($selected as $option) {
                JobApplicationScreeningAnswerOption::query()->create([
                    'application_answer_id' => $answer->id,
                    'application_question_option_id' => $option->id,
                ]);
            }
        }
    }
}
