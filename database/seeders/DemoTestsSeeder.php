<?php

namespace Database\Seeders;

use App\Enums\TestAnswerGradingType;
use App\Enums\TestAttemptGradingStatus;
use App\Enums\TestQuestionType;
use App\Models\ApplicationTestAssignment;
use App\Models\ApplicationTestAssignmentDeadlineChange;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\Test;
use App\Models\TestAnswer;
use App\Models\TestAnswerGrading;
use App\Models\TestAttempt;
use App\Models\TestOption;
use App\Models\TestQuestion;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DemoTestsSeeder extends Seeder
{
    public function run(): void
    {
        $now = DemoSeederContext::now();
        $company = Company::query()->where('name', 'Workey Labs')->firstOrFail();
        $employer = User::query()->where('email', 'employer.approved@workey.test')->firstOrFail();
        $reviewer = User::query()->where('email', 'employer.recruiter@workey.test')->firstOrFail();

        $mixed = $this->mixedTest($company);
        $automatic = $this->automaticTest($company);
        $manual = $this->manualTest($company);
        Test::query()->create([
            'company_id' => $company->id,
            'title' => 'Inactive Legacy Assessment',
            'description' => 'Inactive catalog record used to verify visibility rules.',
            'instructions' => 'This test must not be assigned.',
            'duration_minutes' => 20,
            'max_score' => 0,
            'passing_score' => null,
            'is_active' => false,
        ]);

        $pending = $this->assignment(
            $this->application('test_pending'),
            $mixed,
            $employer,
            $now->copy()->subDay(),
            $now->copy()->addDays(3),
        );

        $expired = $this->assignment(
            $this->application('on_hold'),
            $automatic,
            $employer,
            $now->copy()->subDays(5),
            $now->copy()->subDays(2),
        );

        $extended = $this->assignment(
            $this->application('interview_pending'),
            $automatic,
            $employer,
            $now->copy()->subDays(4),
            $now->copy()->addDays(4),
        );
        ApplicationTestAssignmentDeadlineChange::query()->create([
            'application_test_assignment_id' => $extended->id,
            'previous_deadline_at' => $now->copy()->subDay(),
            'new_deadline_at' => $now->copy()->addDays(4),
            'changed_by_user_id' => $employer->id,
            'reason' => 'Candidate had a documented connectivity issue.',
            'created_at' => $now->copy()->subDays(2),
            'updated_at' => $now->copy()->subDays(2),
        ]);

        $inProgress = $this->assignment(
            $this->application('shortlisted'),
            $mixed,
            $employer,
            $now->copy()->subHours(5),
            $now->copy()->addDay(),
        );
        TestAttempt::query()->create([
            'application_test_assignment_id' => $inProgress->id,
            'answers' => null,
            'started_at' => $now->copy()->subMinutes(20),
            'effective_deadline_at' => $now->copy()->addMinutes(70),
            'submitted_at' => null,
            'grading_status' => TestAttemptGradingStatus::PENDING,
        ]);

        $autoAssignment = $this->assignment(
            $this->application('test_completed'),
            $automatic,
            $employer,
            $now->copy()->subDays(4),
            $now->copy()->subDays(2),
        );
        $this->objectiveAttempt($autoAssignment, $reviewer, $now, passed: true);

        $manualRequired = $this->assignment(
            $this->application('final_review'),
            $mixed,
            $employer,
            $now->copy()->subDays(8),
            $now->copy()->subDays(6),
        );
        $this->mixedAttempt($manualRequired, $reviewer, $now->copy()->subDays(6), fullyGraded: false);

        $fullyGraded = $this->assignment(
            $this->application('accepted'),
            $mixed,
            $employer,
            $now->copy()->subDays(12),
            $now->copy()->subDays(10),
        );
        $this->mixedAttempt($fullyGraded, $reviewer, $now->copy()->subDays(10), fullyGraded: true);

        $failedManual = $this->assignment(
            $this->application('rejected'),
            $manual,
            $employer,
            $now->copy()->subDays(10),
            $now->copy()->subDays(8),
        );
        $this->manualFailedAttempt($failedManual, $reviewer, $now->copy()->subDays(8));

        $root = $this->assignment(
            $this->application('accepted'),
            $automatic,
            $employer,
            $now->copy()->subDays(15),
            $now->copy()->subDays(14),
            maxAttempts: 2,
        );
        $this->objectiveAttempt($root, $reviewer, $now->copy()->subDays(14), passed: false);
        $retake = $this->assignment(
            $this->application('accepted'),
            $automatic,
            $employer,
            $now->copy()->subDays(13),
            $now->copy()->subDays(11),
            attemptNumber: 2,
            maxAttempts: 2,
            seriesRoot: $root,
            previous: $root,
            retakeReason: 'A second attempt was approved after focused preparation.',
        );
        $this->objectiveAttempt($retake, $reviewer, $now->copy()->subDays(11), passed: true);

        unset($pending, $expired);
    }

    private function mixedTest(Company $company): Test
    {
        $test = Test::query()->create([
            'company_id' => $company->id,
            'title' => 'Laravel Engineering Assessment',
            'description' => 'Mixed automatic and manually graded Laravel assessment.',
            'instructions' => 'Answer every required question. Uploaded files must contain no secrets.',
            'duration_minutes' => 90,
            'max_score' => 60,
            'passing_score' => 42,
            'is_active' => true,
        ]);
        $definitions = [
            [TestQuestionType::SINGLE_CHOICE, 'Which HTTP status represents validation failure?', ['422', '201', '204'], [0]],
            [TestQuestionType::MULTIPLE_CHOICE, 'Select relational databases.', ['MySQL', 'PostgreSQL', 'Redis'], [0, 1]],
            [TestQuestionType::TRUE_FALSE, 'Laravel supports database transactions.', ['True', 'False'], [0]],
            [TestQuestionType::SHORT_TEXT, 'Name one method for preventing duplicate processing.', [], []],
            [TestQuestionType::LONG_TEXT, 'Explain an idempotent application submission design.', [], []],
            [TestQuestionType::FILE_UPLOAD, 'Upload a short architecture note.', [], []],
        ];

        foreach ($definitions as $index => [$type, $text, $options, $correct]) {
            $question = TestQuestion::query()->create([
                'test_id' => $test->id,
                'question_text' => $text,
                'question_type' => $type,
                'order_index' => $index + 1,
                'points' => 10,
                'is_required' => true,
            ]);
            foreach ($options as $optionIndex => $optionText) {
                TestOption::query()->create([
                    'test_question_id' => $question->id,
                    'option_text' => $optionText,
                    'order_index' => $optionIndex + 1,
                    'is_correct' => in_array($optionIndex, $correct, true),
                ]);
            }
        }

        return $test;
    }

    private function automaticTest(Company $company): Test
    {
        $test = Test::query()->create([
            'company_id' => $company->id,
            'title' => 'API Fundamentals Quiz',
            'description' => 'Short automatically graded objective quiz.',
            'instructions' => 'Choose the best answer.',
            'duration_minutes' => 30,
            'max_score' => 30,
            'passing_score' => 21,
            'is_active' => true,
        ]);
        foreach ([
            ['A successful resource creation commonly returns:', ['201', '404'], 0],
            ['A transaction should be rolled back after an exception.', ['True', 'False'], 0],
            ['Select an idempotent HTTP method.', ['GET', 'POST'], 0],
        ] as $index => [$text, $options, $correct]) {
            $question = TestQuestion::query()->create([
                'test_id' => $test->id,
                'question_text' => $text,
                'question_type' => $index === 1 ? TestQuestionType::TRUE_FALSE : TestQuestionType::SINGLE_CHOICE,
                'order_index' => $index + 1,
                'points' => 10,
                'is_required' => true,
            ]);
            foreach ($options as $optionIndex => $optionText) {
                TestOption::query()->create([
                    'test_question_id' => $question->id,
                    'option_text' => $optionText,
                    'order_index' => $optionIndex + 1,
                    'is_correct' => $optionIndex === $correct,
                ]);
            }
        }

        return $test;
    }

    private function manualTest(Company $company): Test
    {
        $test = Test::query()->create([
            'company_id' => $company->id,
            'title' => 'Architecture Writing Exercise',
            'description' => 'Manual architecture review exercise.',
            'instructions' => 'Explain trade-offs clearly.',
            'duration_minutes' => 45,
            'max_score' => 20,
            'passing_score' => 15,
            'is_active' => true,
        ]);
        TestQuestion::query()->create([
            'test_id' => $test->id,
            'question_text' => 'Compare optimistic and pessimistic concurrency controls.',
            'question_type' => TestQuestionType::LONG_TEXT,
            'order_index' => 1,
            'points' => 20,
            'is_required' => true,
        ]);

        return $test;
    }

    private function assignment(
        JobApplication $application,
        Test $test,
        User $employer,
        Carbon $assignedAt,
        ?Carbon $deadline,
        int $attemptNumber = 1,
        int $maxAttempts = 1,
        ?ApplicationTestAssignment $seriesRoot = null,
        ?ApplicationTestAssignment $previous = null,
        ?string $retakeReason = null,
    ): ApplicationTestAssignment {
        return ApplicationTestAssignment::query()->create([
            'job_application_id' => $application->id,
            'series_root_assignment_id' => $seriesRoot?->id,
            'previous_assignment_id' => $previous?->id,
            'attempt_number' => $attemptNumber,
            'max_attempts' => $maxAttempts,
            'test_id' => $test->id,
            'assigned_by_user_id' => $employer->id,
            'retake_granted_by_user_id' => $retakeReason === null ? null : $employer->id,
            'note' => $retakeReason === null ? 'Complete the assigned assessment before the deadline.' : 'Retake instructions remain unchanged.',
            'retake_reason' => $retakeReason,
            'assigned_at' => $assignedAt,
            'deadline_at' => $deadline,
        ]);
    }

    private function objectiveAttempt(ApplicationTestAssignment $assignment, User $reviewer, Carbon $submittedAt, bool $passed): void
    {
        $attempt = TestAttempt::query()->create([
            'application_test_assignment_id' => $assignment->id,
            'answers' => null,
            'started_at' => $submittedAt->copy()->subMinutes(20),
            'effective_deadline_at' => $assignment->deadline_at,
            'submitted_at' => $submittedAt,
            'objective_score' => $passed ? 30 : 10,
            'objective_max_score' => 30,
            'manual_score' => 0,
            'manual_max_score' => 0,
            'total_score' => $passed ? 30 : 10,
            'max_score' => 30,
            'percentage' => $passed ? 100 : 33.33,
            'grading_status' => TestAttemptGradingStatus::AUTO_GRADED,
            'auto_graded_at' => $submittedAt,
            'score' => $passed ? 30 : 10,
            'feedback' => $passed ? 'Passed the objective quiz.' : 'Retake recommended.',
            'evaluated_by_user_id' => $reviewer->id,
            'evaluated_at' => $submittedAt,
        ]);

        foreach ($assignment->test->questions()->with('options')->get() as $index => $question) {
            $option = $passed || $index === 0
                ? $question->options->firstWhere('is_correct', true)
                : $question->options->firstWhere('is_correct', false);
            $answer = TestAnswer::query()->create([
                'test_attempt_id' => $attempt->id,
                'test_question_id' => $question->id,
            ]);
            $answer->selectedOptions()->sync([$option->id]);
            $correct = (bool) $option->is_correct;
            TestAnswerGrading::query()->create([
                'test_answer_id' => $answer->id,
                'grading_type' => TestAnswerGradingType::AUTOMATIC,
                'is_correct' => $correct,
                'awarded_points' => $correct ? 10 : 0,
                'max_points' => 10,
                'explanation' => $correct ? 'Selected the configured correct option.' : 'Selected an incorrect option.',
                'graded_by' => null,
                'graded_at' => $submittedAt,
            ]);
        }
    }

    private function mixedAttempt(ApplicationTestAssignment $assignment, User $reviewer, Carbon $submittedAt, bool $fullyGraded): void
    {
        $attempt = TestAttempt::query()->create([
            'application_test_assignment_id' => $assignment->id,
            'answers' => null,
            'started_at' => $submittedAt->copy()->subMinutes(70),
            'effective_deadline_at' => $assignment->deadline_at,
            'submitted_at' => $submittedAt,
            'objective_score' => 30,
            'objective_max_score' => 30,
            'manual_score' => $fullyGraded ? 25 : null,
            'manual_max_score' => 30,
            'total_score' => $fullyGraded ? 55 : null,
            'max_score' => 60,
            'percentage' => $fullyGraded ? 91.67 : null,
            'grading_status' => $fullyGraded
                ? TestAttemptGradingStatus::FULLY_GRADED
                : TestAttemptGradingStatus::MANUAL_GRADING_REQUIRED,
            'auto_graded_at' => $submittedAt,
            'manually_graded_at' => $fullyGraded ? $submittedAt->copy()->addDay() : null,
            'score' => $fullyGraded ? 55 : null,
            'feedback' => $fullyGraded ? 'Strong solution with clear trade-offs.' : 'Awaiting manual review.',
            'evaluated_by_user_id' => $fullyGraded ? $reviewer->id : null,
            'evaluated_at' => $fullyGraded ? $submittedAt->copy()->addDay() : null,
        ]);

        foreach ($assignment->test->questions()->with('options')->get() as $question) {
            $answer = $this->answerForQuestion($attempt, $question);
            if ($question->question_type->acceptsOptions()) {
                TestAnswerGrading::query()->create([
                    'test_answer_id' => $answer->id,
                    'grading_type' => TestAnswerGradingType::AUTOMATIC,
                    'is_correct' => true,
                    'awarded_points' => 10,
                    'max_points' => 10,
                    'explanation' => 'All configured correct options were selected.',
                    'graded_by' => null,
                    'graded_at' => $submittedAt,
                ]);
            } elseif ($fullyGraded) {
                $points = $question->question_type === TestQuestionType::FILE_UPLOAD ? 5 : 10;
                TestAnswerGrading::query()->create([
                    'test_answer_id' => $answer->id,
                    'grading_type' => TestAnswerGradingType::MANUAL,
                    'is_correct' => null,
                    'awarded_points' => $points,
                    'max_points' => 10,
                    'explanation' => 'Reviewer note: clear, relevant, and safe answer.',
                    'graded_by' => $reviewer->id,
                    'graded_at' => $submittedAt->copy()->addDay(),
                ]);
            }
        }
    }

    private function answerForQuestion(TestAttempt $attempt, TestQuestion $question): TestAnswer
    {
        $file = null;
        if ($question->question_type === TestQuestionType::FILE_UPLOAD) {
            $file = 'demo-seed/test-answers/architecture-note.txt';
            Storage::disk('local')->put($file, "Architecture note\nUse transactions, unique constraints, and idempotency keys.\n");
        }

        $answer = TestAnswer::query()->create([
            'test_attempt_id' => $attempt->id,
            'test_question_id' => $question->id,
            'answer_text' => match ($question->question_type) {
                TestQuestionType::SHORT_TEXT => 'Use an idempotency key backed by a unique constraint.',
                TestQuestionType::LONG_TEXT => 'Validate ownership, lock the aggregate, persist snapshots in one transaction, and retry safely.',
                default => null,
            },
            'file_path' => $file,
            'file_disk' => $file === null ? null : 'local',
            'file_original_name' => $file === null ? null : 'architecture-note.txt',
            'file_mime_type' => $file === null ? null : 'text/plain',
            'file_size' => $file === null ? null : Storage::disk('local')->size($file),
        ]);

        if ($question->question_type->acceptsOptions()) {
            $answer->selectedOptions()->sync($question->options->where('is_correct', true)->pluck('id')->all());
        }

        return $answer;
    }

    private function manualFailedAttempt(ApplicationTestAssignment $assignment, User $reviewer, Carbon $submittedAt): void
    {
        $attempt = TestAttempt::query()->create([
            'application_test_assignment_id' => $assignment->id,
            'started_at' => $submittedAt->copy()->subMinutes(35),
            'effective_deadline_at' => $assignment->deadline_at,
            'submitted_at' => $submittedAt,
            'objective_score' => 0,
            'objective_max_score' => 0,
            'manual_score' => 8,
            'manual_max_score' => 20,
            'total_score' => 8,
            'max_score' => 20,
            'percentage' => 40,
            'grading_status' => TestAttemptGradingStatus::FULLY_GRADED,
            'auto_graded_at' => $submittedAt,
            'manually_graded_at' => $submittedAt->copy()->addHours(4),
            'score' => 8,
            'feedback' => 'The answer did not explain consistency trade-offs.',
            'evaluated_by_user_id' => $reviewer->id,
            'evaluated_at' => $submittedAt->copy()->addHours(4),
        ]);
        $question = $assignment->test->questions()->firstOrFail();
        $answer = TestAnswer::query()->create([
            'test_attempt_id' => $attempt->id,
            'test_question_id' => $question->id,
            'answer_text' => 'Both approaches lock data, but the trade-offs were not fully explained.',
        ]);
        TestAnswerGrading::query()->create([
            'test_answer_id' => $answer->id,
            'grading_type' => TestAnswerGradingType::MANUAL,
            'is_correct' => null,
            'awarded_points' => 8,
            'max_points' => 20,
            'explanation' => 'Reviewer note: incomplete comparison and no conflict-handling example.',
            'graded_by' => $reviewer->id,
            'graded_at' => $submittedAt->copy()->addHours(4),
        ]);
    }

    private function application(string $status): JobApplication
    {
        return JobApplication::query()
            ->whereHas('applicationStatus', fn ($query) => $query->where('slug', $status))
            ->firstOrFail();
    }
}
