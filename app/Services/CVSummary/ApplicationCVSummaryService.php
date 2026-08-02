<?php

namespace App\Services\CVSummary;

use App\Contracts\CVSummary\CVSummaryClient;
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
    /** @var list<string> */
    private const SENSITIVE_CV_KEYS = [
        'name',
        'full_name',
        'first_name',
        'last_name',
        'email',
        'email_address',
        'phone',
        'phone_number',
        'mobile',
        'telephone',
        'location',
        'address',
        'home_address',
        'birth_date',
        'date_of_birth',
        'dob',
        'age',
        'nationality',
        'citizenship',
        'marital_status',
        'gender',
        'sex',
        'religion',
        'disability',
        'ethnicity',
        'race',
        'protected_attributes',
        'sensitive_attributes',
        'contact',
        'contact_details',
    ];

    public function __construct(
        private readonly CVSummaryClient $client,
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
        $provider = $this->client->provider();
        $model = $this->client->model();
        $inputHash = hash('sha256', json_encode([
            'provider' => $provider,
            'prompt_version' => (string) config('cv_summary.prompt_version', '1.0'),
            'model' => $model,
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
                'provider' => $provider,
                'model' => $model,
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
                'application_id' => $application->id,
                'summary_id' => $summary->id,
                'locale' => $summary->locale,
                'provider' => $summary->provider,
                'model' => $summary->model,
                'prompt_version' => $summary->prompt_version,
                'input_hash' => $summary->input_hash,
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
            $cvData = $this->removeSensitiveFields(Arr::except($cvData, ['_meta']));
        } else {
            $rawText = trim((string) $cvResult?->raw_text);
            $cvData = $rawText === '' ? null : [
                'redacted_raw_text' => $this->redactAndLimit($rawText, $application),
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

        return $this->redactSource([
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
        ], $application);
    }

    /** @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    private function removeSensitiveFields(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = Str::of((string) $key)->snake()->lower()->toString();
            if (in_array($normalizedKey, self::SENSITIVE_CV_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->removeSensitiveFields($value)
                : $value;
        }

        return $sanitized;
    }

    /** @param array<string|int, mixed> $source
     * @return array<string|int, mixed>
     */
    private function redactSource(array $source, JobApplication $application): array
    {
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                $source[$key] = $this->redactSource($value, $application);
            } elseif (is_string($value)) {
                $source[$key] = $this->redactText($value, $application);
            }
        }

        return $source;
    }

    private function redactAndLimit(string $text, JobApplication $application): string
    {
        $text = $this->redactText($text, $application);

        return Str::limit($text, max(1000, (int) config('cv_summary.max_source_characters', 30000)), '');
    }

    private function redactText(string $text, JobApplication $application): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[redacted-email]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\+?\d[\d\s().-]{7,}\d)(?!\d)/u', '[redacted-phone]', $text) ?? $text;
        $text = preg_replace(
            '/(^|\R)\s*(?:date[ _-]?of[ _-]?birth|birth[ _-]?date|dob|age|nationality|citizenship|marital[ _-]?status|gender|sex|religion|disability|ethnicity|race|تاريخ الميلاد|العمر|الجنسية|الحالة الاجتماعية|الجنس|الديانة|الدين|الإعاقة)\s*[:\-]\s*[^\r\n]*/iu',
            '$1[redacted-sensitive]',
            $text,
        ) ?? $text;

        foreach (array_filter([
            $application->jobSeekerProfile?->user?->name,
            $application->jobSeekerProfile?->user?->email,
            $application->jobSeekerProfile?->phone,
        ], fn ($value): bool => is_string($value) && trim($value) !== '') as $identifier) {
            $text = preg_replace('/'.preg_quote($identifier, '/').'/iu', '[redacted-identifier]', $text) ?? $text;
        }

        return $text;
    }

    private function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
