<?php

namespace App\Services\CVSummary;

use App\Exceptions\CVSummaryGenerationException;
use App\Models\ApplicationCVSummary;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\AuditLogService;
use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ApplicationCVSummaryService
{
    public function __construct(
        private readonly OpenAICVSummaryClient $client,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function find(JobApplication $application, string $locale): ?ApplicationCVSummary
    {
        return ApplicationCVSummary::query()
            ->where('job_application_id', $application->id)
            ->where('locale', $locale)
            ->first();
    }

    public function generate(
        JobApplication $application,
        User $actor,
        string $locale,
        bool $force = false,
    ): ApplicationCVSummary {
        $application->loadMissing([
            'jobPosting.company',
            'jobPosting.skills',
            'jobSeekerProfile.user',
            'jobSeekerProfile.skills',
            'jobSeekerProfile.experiences',
            'jobSeekerProfile.education',
            'selectedCvFile.parsingResult',
        ]);

        $source = $this->buildSource($application);
        $inputHash = hash('sha256', json_encode([
            'prompt_version' => (string) config('cv_summary.prompt_version', '1.0'),
            'model' => (string) config('cv_summary.openai.model', 'gpt-5-mini'),
            'locale' => $locale,
            'source' => $source,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $existing = $this->find($application, $locale);
        if (! $force && $existing?->input_hash === $inputHash) {
            return $existing;
        }

        $result = $this->client->generate($source, $locale);
        $data = $result['data'];

        $summary = ApplicationCVSummary::query()->updateOrCreate(
            [
                'job_application_id' => $application->id,
                'locale' => $locale,
            ],
            [
                'source_cv_file_id' => $application->selected_cv_file_id,
                'generated_by_user_id' => $actor->id,
                'provider' => 'openai',
                'model' => (string) config('cv_summary.openai.model', 'gpt-5-mini'),
                'prompt_version' => (string) config('cv_summary.prompt_version', '1.0'),
                'input_hash' => $inputHash,
                'headline' => $data['headline'],
                'summary' => $data['summary'],
                'strengths' => $data['strengths'],
                'gaps' => $data['gaps'],
                'evidence' => $data['evidence'],
                'provider_request_id' => $result['request_id'],
                'generated_at' => now(),
            ],
        );

        $this->auditLogService->record(
            'application.cv_summary_generated',
            $actor,
            JobApplication::class,
            $application->id,
            $existing === null ? null : [
                'input_hash' => $existing->input_hash,
                'model' => $existing->model,
                'locale' => $existing->locale,
            ],
            [
                'input_hash' => $summary->input_hash,
                'model' => $summary->model,
                'locale' => $summary->locale,
            ],
            [
                'provider' => $summary->provider,
                'force' => $force,
                'source_cv_file_id' => $summary->source_cv_file_id,
            ],
        );

        return $summary;
    }

    /** @return array<string, mixed> */
    private function buildSource(JobApplication $application): array
    {
        $profile = $application->jobSeekerProfile;
        $job = $application->jobPosting;
        $cvResult = $application->selectedCvFile?->parsingResult;

        $cvData = $cvResult?->reviewed_json ?? $cvResult?->parsed_json;
        if (is_array($cvData)) {
            $cvData = Arr::except($cvData, [
                'full_name',
                'email',
                'phone',
                'location',
                'birth_date',
                'nationality',
                'marital_status',
                '_meta',
            ]);
        } else {
            $rawText = trim((string) $cvResult?->raw_text);
            $cvData = $rawText === '' ? null : [
                'redacted_raw_text' => $this->redactAndLimit($rawText),
            ];
        }

        if ($cvData === null
            && blank($profile?->headline)
            && blank($profile?->summary)
            && $profile?->skills->isEmpty()
            && $profile?->experiences->isEmpty()
            && $profile?->education->isEmpty()) {
            throw new CVSummaryGenerationException(
                __('cv_summary.source_unavailable'),
                'CV_SUMMARY_SOURCE_UNAVAILABLE',
                422,
            );
        }

        return [
            'job' => [
                'title' => $job->title,
                'description' => $job->description,
                'responsibilities' => $job->responsibilities,
                'requirements' => $job->requirements,
                'experience_level' => $job->experience_level,
                'education_level' => $job->education_level,
                'skills' => $job->skills->map(fn ($skill): array => [
                    'name' => $skill->name,
                    'requirement_type' => $this->scalar($skill->pivot?->requirement_type),
                    'weight' => $skill->pivot?->weight,
                ])->values()->all(),
            ],
            'verified_profile' => [
                'headline' => $profile?->headline,
                'professional_summary' => $profile?->summary,
                'skills' => $profile?->skills->pluck('name')->values()->all() ?? [],
                'experience' => $profile?->experiences->map(fn ($experience): array => [
                    'title' => $experience->title,
                    'company_name' => $experience->company_name,
                    'start_date' => $experience->start_date?->format('Y-m'),
                    'end_date' => $experience->end_date?->format('Y-m'),
                    'is_current' => $experience->is_current,
                    'description' => $experience->description,
                ])->values()->all() ?? [],
                'education' => $profile?->education->map(fn ($education): array => [
                    'degree' => $education->degree,
                    'field_of_study' => $education->field_of_study,
                    'institution' => $education->institution,
                    'start_date' => $education->start_date?->format('Y-m'),
                    'end_date' => $education->end_date?->format('Y-m'),
                    'description' => $education->description,
                ])->values()->all() ?? [],
            ],
            'selected_cv' => $cvData,
        ];
    }

    private function redactAndLimit(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[redacted-email]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\+?\d[\d\s().-]{7,}\d)(?!\d)/u', '[redacted-phone]', $text) ?? $text;

        return Str::limit($text, max(1000, (int) config('cv_summary.max_source_characters', 30000)), '');
    }

    private function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
