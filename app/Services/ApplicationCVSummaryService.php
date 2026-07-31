<?php

namespace App\Services;

use App\Exceptions\ApplicationCVSummaryException;
use App\Models\ApplicationCVSummary;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\AI\OpenAIApplicationCVSummarizer;
use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ApplicationCVSummaryService
{
    public function __construct(
        private readonly OpenAIApplicationCVSummarizer $summarizer,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function find(JobApplication $application, string $locale): ApplicationCVSummary
    {
        $summary = ApplicationCVSummary::query()
            ->where('job_application_id', $application->id)
            ->where('locale', $locale)
            ->first();

        if (! $summary instanceof ApplicationCVSummary) {
            throw new ApplicationCVSummaryException(__('applications.cv_summary_not_found'), 'CV_SUMMARY_NOT_FOUND', 404);
        }

        $summary->setAttribute('is_stale', ! hash_equals($summary->input_hash, $this->inputHash($application, $locale)));

        return $summary;
    }

    public function generate(User $actor, JobApplication $application, string $locale, bool $force = false): ApplicationCVSummary
    {
        $context = $this->context($application);
        $inputHash = $this->hash($context, $locale);
        $existing = ApplicationCVSummary::query()
            ->where('job_application_id', $application->id)
            ->where('locale', $locale)
            ->first();

        if (! $force && $existing instanceof ApplicationCVSummary && hash_equals($existing->input_hash, $inputHash)) {
            $existing->setAttribute('is_stale', false);

            return $existing;
        }

        $result = $this->summarizer->summarize($context, $locale);

        $summary = DB::transaction(fn (): ApplicationCVSummary => ApplicationCVSummary::query()->updateOrCreate(
            ['job_application_id' => $application->id, 'locale' => $locale],
            [
                'source_cv_file_id' => $application->selected_cv_file_id,
                'generated_by_user_id' => $actor->id,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'prompt_version' => (string) config('ai.cv_summary.prompt_version', '1.0'),
                'input_hash' => $inputHash,
                'headline' => $result['headline'],
                'summary' => $result['summary'],
                'strengths' => $result['strengths'],
                'gaps' => $result['gaps'],
                'evidence' => $result['evidence'],
                'provider_request_id' => $result['provider_request_id'],
                'generated_at' => now(),
            ],
        ));

        $this->auditLogService->record(
            'application.cv_summary_generated',
            $actor,
            JobApplication::class,
            $application->id,
            null,
            null,
            [
                'summary_id' => $summary->id,
                'locale' => $locale,
                'provider' => $summary->provider,
                'model' => $summary->model,
                'prompt_version' => $summary->prompt_version,
                'input_hash' => $inputHash,
            ],
        );

        $summary->setAttribute('is_stale', false);

        return $summary;
    }

    private function inputHash(JobApplication $application, string $locale): string
    {
        return $this->hash($this->context($application), $locale);
    }

    private function hash(array $context, string $locale): string
    {
        $payload = [
            'locale' => $locale,
            'model' => (string) config('ai.cv_summary.model', 'gpt-5-mini'),
            'prompt_version' => (string) config('ai.cv_summary.prompt_version', '1.0'),
            'context' => $this->sortRecursively($context),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function context(JobApplication $application): array
    {
        $application->loadMissing([
            'jobPosting.skills',
            'jobSeekerProfile.skills',
            'jobSeekerProfile.experiences',
            'jobSeekerProfile.education',
            'selectedCvFile.parsingResult',
        ]);

        $job = $application->jobPosting;
        $profile = $application->jobSeekerProfile;
        $parsed = $application->selectedCvFile?->parsingResult?->reviewed_json
            ?? $application->selectedCvFile?->parsingResult?->parsed_json
            ?? [];

        $context = [
            'job' => [
                'title' => $job?->title,
                'description' => $job?->description,
                'responsibilities' => $job?->responsibilities,
                'requirements' => $job?->requirements,
                'experience_level' => $job?->experience_level,
                'education_level' => $job?->education_level,
                'skills' => $job?->skills?->map(function ($skill): array {
                    $requirementType = $skill->pivot?->requirement_type;

                    return [
                        'name' => $skill->name,
                        'requirement_type' => $requirementType instanceof BackedEnum
                            ? $requirementType->value
                            : $requirementType,
                        'weight' => $skill->pivot?->weight,
                    ];
                })->values()->all() ?? [],
            ],
            'candidate' => [
                'headline' => $profile?->headline,
                'professional_summary' => $profile?->summary,
                'skills' => $profile?->skills?->pluck('name')->values()->all() ?? [],
                'experience' => $profile?->experiences?->map(fn ($experience): array => [
                    'title' => $experience->title,
                    'company_name' => $experience->company_name,
                    'start_date' => $experience->start_date?->format('Y-m'),
                    'end_date' => $experience->end_date?->format('Y-m'),
                    'is_current' => $experience->is_current,
                    'description' => $experience->description,
                ])->values()->all() ?? [],
                'education' => $profile?->education?->map(fn ($education): array => [
                    'degree' => $education->degree,
                    'field_of_study' => $education->field_of_study,
                    'institution' => $education->institution,
                    'start_date' => $education->start_date?->format('Y-m'),
                    'end_date' => $education->end_date?->format('Y-m'),
                    'description' => $education->description,
                ])->values()->all() ?? [],
                'selected_cv' => Arr::only(is_array($parsed) ? $parsed : [], [
                    'summary', 'experience', 'education', 'certifications', 'skills', 'languages',
                ]),
            ],
        ];

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $maximum = max(5000, (int) config('ai.cv_summary.max_input_characters', 30000));
        if (mb_strlen($encoded) > $maximum) {
            $context['candidate']['selected_cv'] = [];
            $context['input_truncated'] = true;
        }

        return $context;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
    }
}
