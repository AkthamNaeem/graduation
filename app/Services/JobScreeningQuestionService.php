<?php

namespace App\Services;

use App\Enums\ScreeningQuestionType;
use App\Exceptions\JobPostingOperationException;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class JobScreeningQuestionService
{
    public const MAX_ACTIVE_QUESTIONS = 50;

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly CompanyRecruitmentAccessService $companyAccessService,
    ) {}

    /** @return Collection<int, JobScreeningQuestion> */
    public function activeForJob(JobPosting $jobPosting): Collection
    {
        return $jobPosting->screeningQuestions()
            ->where('is_active', true)
            ->with('options')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, JobPosting $jobPosting, array $data): JobScreeningQuestion
    {
        return DB::transaction(function () use ($actor, $jobPosting, $data): JobScreeningQuestion {
            $company = $this->companyAccessService->assertEmployerCanRecruit($actor);
            $lockedJob = JobPosting::query()->lockForUpdate()->findOrFail($jobPosting->id);
            $this->assertActorOwnsJob($company->id, $lockedJob);
            $this->assertQuestionsAreEditable($lockedJob);

            if ($lockedJob->screeningQuestions()->where('is_active', true)->count() >= self::MAX_ACTIVE_QUESTIONS) {
                throw new JobPostingOperationException(
                    'The maximum number of active screening questions has been reached.',
                    'JOB_SCREENING_QUESTION_LIMIT_REACHED',
                    422,
                );
            }

            $type = ScreeningQuestionType::from($data['question_type']);
            $options = $this->validatedOptions($type, $data, requireChoiceOptions: true);
            $sortOrder = array_key_exists('sort_order', $data)
                ? (int) $data['sort_order']
                : ((int) $lockedJob->screeningQuestions()->max('sort_order')) + 1;

            $question = $lockedJob->screeningQuestions()->create([
                'question_text' => trim((string) $data['question_text']),
                'question_type' => $type,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_by_user_id' => $actor->id,
            ]);
            $this->replaceOptions($question, $options);
            $this->audit($actor, $question, 'job.screening_question.created', null, $this->safeState($question));

            return $question->load('options');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, JobPosting $jobPosting, JobScreeningQuestion $question, array $data): JobScreeningQuestion
    {
        return DB::transaction(function () use ($actor, $jobPosting, $question, $data): JobScreeningQuestion {
            $company = $this->companyAccessService->assertEmployerCanRecruit($actor);
            $lockedJob = JobPosting::query()->lockForUpdate()->findOrFail($jobPosting->id);
            $this->assertActorOwnsJob($company->id, $lockedJob);
            $this->assertQuestionsAreEditable($lockedJob);
            $lockedQuestion = JobScreeningQuestion::query()
                ->with('options')
                ->lockForUpdate()
                ->findOrFail($question->id);
            $this->assertQuestionBelongsToJob($lockedQuestion, $lockedJob);

            $before = $this->safeState($lockedQuestion);
            $type = array_key_exists('question_type', $data)
                ? ScreeningQuestionType::from($data['question_type'])
                : $lockedQuestion->question_type;
            $switchingToChoice = ! $lockedQuestion->question_type->isChoice() && $type->isChoice();
            $options = $this->validatedOptions($type, $data, requireChoiceOptions: $switchingToChoice);

            $updates = [];
            foreach (['question_text', 'is_required', 'sort_order'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $field === 'question_text' ? trim((string) $data[$field]) : $data[$field];
                }
            }
            if (array_key_exists('question_type', $data)) {
                $updates['question_type'] = $type;
            }
            $lockedQuestion->update($updates);

            if (! $type->isChoice() && $lockedQuestion->options->isNotEmpty()) {
                $lockedQuestion->options()->delete();
            } elseif (array_key_exists('options', $data)) {
                $this->replaceOptions($lockedQuestion, $options);
            }

            $lockedQuestion->load('options');
            $this->audit($actor, $lockedQuestion, 'job.screening_question.updated', $before, $this->safeState($lockedQuestion));

            return $lockedQuestion;
        });
    }

    public function deactivate(User $actor, JobPosting $jobPosting, JobScreeningQuestion $question): void
    {
        DB::transaction(function () use ($actor, $jobPosting, $question): void {
            $company = $this->companyAccessService->assertEmployerCanRecruit($actor);
            $lockedJob = JobPosting::query()->lockForUpdate()->findOrFail($jobPosting->id);
            $this->assertActorOwnsJob($company->id, $lockedJob);
            $this->assertQuestionsAreEditable($lockedJob);
            $lockedQuestion = JobScreeningQuestion::query()->lockForUpdate()->findOrFail($question->id);
            $this->assertQuestionBelongsToJob($lockedQuestion, $lockedJob);
            $before = $this->safeState($lockedQuestion);

            $lockedQuestion->update(['is_active' => false]);
            $this->audit($actor, $lockedQuestion, 'job.screening_question.deactivated', $before, $this->safeState($lockedQuestion));
        });
    }

    private function assertQuestionsAreEditable(JobPosting $jobPosting): void
    {
        if ($jobPosting->status === 'closed') {
            throw new JobPostingOperationException(
                'Screening questions cannot be changed for a closed job.',
                'JOB_SCREENING_QUESTION_JOB_CLOSED',
                409,
            );
        }
    }

    private function assertActorOwnsJob(int $companyId, JobPosting $jobPosting): void
    {
        if ($companyId !== $jobPosting->company_id) {
            throw new JobPostingOperationException(
                'The screening question cannot be managed by this company.',
                'JOB_SCREENING_QUESTION_FORBIDDEN',
                403,
            );
        }
    }

    private function assertQuestionBelongsToJob(JobScreeningQuestion $question, JobPosting $jobPosting): void
    {
        if ($question->job_posting_id !== $jobPosting->id) {
            throw new JobPostingOperationException(
                'The screening question does not belong to this job.',
                'JOB_SCREENING_QUESTION_NOT_FOUND',
                404,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{option_text: string, sort_order: int}>
     */
    private function validatedOptions(ScreeningQuestionType $type, array $data, bool $requireChoiceOptions): array
    {
        $hasOptions = array_key_exists('options', $data);
        if (! $type->isChoice()) {
            if ($hasOptions) {
                throw new JobPostingOperationException(
                    'Options are not allowed for this question type.',
                    'JOB_SCREENING_QUESTION_OPTIONS_NOT_ALLOWED',
                    422,
                    ['options' => ['Options are only allowed for choice questions.']],
                );
            }

            return [];
        }

        if (! $hasOptions) {
            if ($requireChoiceOptions) {
                throw new JobPostingOperationException(
                    'Choice questions require at least two options.',
                    'JOB_SCREENING_QUESTION_OPTIONS_REQUIRED',
                    422,
                    ['options' => ['At least two options are required.']],
                );
            }

            return [];
        }

        $options = is_array($data['options']) ? array_values($data['options']) : [];
        if (count($options) < 2 || count($options) > 50) {
            throw new JobPostingOperationException(
                'Choice questions require between two and fifty options.',
                'JOB_SCREENING_QUESTION_OPTIONS_REQUIRED',
                422,
                ['options' => ['Provide between 2 and 50 options.']],
            );
        }

        $normalized = [];
        $result = [];
        foreach ($options as $index => $option) {
            $text = trim((string) ($option['option_text'] ?? ''));
            $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $text));
            if ($text === '' || mb_strlen($text) > 1000) {
                throw new JobPostingOperationException(
                    'Every option must contain valid text.',
                    'JOB_SCREENING_QUESTION_OPTIONS_REQUIRED',
                    422,
                    ["options.{$index}.option_text" => ['The option text is invalid.']],
                );
            }
            if (isset($normalized[$key])) {
                throw new JobPostingOperationException(
                    'Duplicate options are not allowed.',
                    'JOB_SCREENING_QUESTION_DUPLICATE_OPTION',
                    422,
                    ['options' => ['Option text must be unique within a question.']],
                );
            }
            $normalized[$key] = true;
            $result[] = [
                'option_text' => $text,
                'sort_order' => array_key_exists('sort_order', $option) ? (int) $option['sort_order'] : $index + 1,
            ];
        }

        return $result;
    }

    /** @param array<int, array{option_text: string, sort_order: int}> $options */
    private function replaceOptions(JobScreeningQuestion $question, array $options): void
    {
        $question->options()->delete();
        if ($options !== []) {
            $question->options()->createMany($options);
        }
    }

    /** @return array<string, mixed> */
    private function safeState(JobScreeningQuestion $question): array
    {
        return [
            'question_type' => $question->question_type->value,
            'is_required' => $question->is_required,
            'sort_order' => $question->sort_order,
            'is_active' => $question->is_active,
            'option_count' => $question->options()->count(),
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(User $actor, JobScreeningQuestion $question, string $action, ?array $before, array $after): void
    {
        $this->auditLogService->record(
            $action,
            $actor,
            JobScreeningQuestion::class,
            $question->id,
            $before,
            $after,
            ['job_id' => $question->job_posting_id, 'question_id' => $question->id],
        );
    }
}
