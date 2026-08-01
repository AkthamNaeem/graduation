<?php

namespace App\Services;

use App\Enums\ScreeningQuestionType;
use App\Exceptions\ApplicationSnapshotException;
use App\Exceptions\CVLifecycleException;
use App\Exceptions\PrivateFileStorageException;
use App\Models\ApplicationSnapshot;
use App\Models\CVFile;
use App\Models\JobApplication;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\CV\CVFileAccessService;
use Throwable;

class ApplicationSnapshotService
{
    public function __construct(
        private readonly PrivateFileStorageService $privateStorage,
        private readonly CVFileAccessService $cvAccess,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<int, array{question: mixed, answered: bool, text_value: ?string, number_value: int|float|null, boolean_value: ?bool, selected_option_ids: array<int, int>}>  $screeningPlan
     */
    public function capture(
        JobApplication $application,
        JobSeekerProfile $profile,
        CVFile $cvFile,
        array $screeningPlan,
        string $origin = ApplicationSnapshot::ORIGIN_SUBMISSION,
        string $accuracy = ApplicationSnapshot::ACCURACY_EXACT,
    ): ApplicationSnapshot {
        if ($application->snapshot()->exists()) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_ALREADY_EXISTS'),
                'APPLICATION_SNAPSHOT_ALREADY_EXISTS',
            );
        }

        return $this->persist(
            $application,
            $cvFile,
            $this->profileSnapshot($profile),
            $this->answersSnapshot($screeningPlan),
            $origin,
            $accuracy,
            $profile->user,
        );
    }

    public function validateBackfillCandidate(JobApplication $application): void
    {
        if (! $application->jobSeekerProfile instanceof JobSeekerProfile
            || ! $application->selectedCvFile instanceof CVFile) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_SOURCE_INCOMPLETE'),
                'APPLICATION_SNAPSHOT_SOURCE_INCOMPLETE',
                422,
            );
        }

        try {
            $this->cvAccess->assertDownloadable($application->selectedCvFile);
        } catch (CVLifecycleException $exception) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_CV_UNAVAILABLE'),
                'APPLICATION_SNAPSHOT_CV_UNAVAILABLE',
                $exception->status,
                previous: $exception,
            );
        }
    }

    public function backfill(JobApplication $application): ApplicationSnapshot
    {
        $application->loadMissing([
            'jobSeekerProfile.user',
            'jobSeekerProfile.city',
            'jobSeekerProfile.experiences',
            'jobSeekerProfile.education',
            'jobSeekerProfile.skills',
            'selectedCvFile',
            'screeningQuestionSnapshots.options',
            'screeningQuestionSnapshots.answer.selectedOptions.option',
        ]);
        $this->validateBackfillCandidate($application);

        if ($application->snapshot()->exists()) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_ALREADY_EXISTS'),
                'APPLICATION_SNAPSHOT_ALREADY_EXISTS',
            );
        }

        return $this->persist(
            $application,
            $application->selectedCvFile,
            $this->profileSnapshot($application->jobSeekerProfile),
            $this->backfilledAnswersSnapshot($application),
            ApplicationSnapshot::ORIGIN_BACKFILL,
            ApplicationSnapshot::ACCURACY_BEST_AVAILABLE,
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $profileSnapshot
     * @param  array<int|string, mixed>  $answersSnapshot
     */
    private function persist(
        JobApplication $application,
        CVFile $cvFile,
        array $profileSnapshot,
        array $answersSnapshot,
        string $origin,
        string $accuracy,
        ?User $actor,
    ): ApplicationSnapshot {
        try {
            $stored = $this->copyCV($application, $cvFile);
        } catch (PrivateFileStorageException $exception) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_CV_COPY_FAILED'),
                'APPLICATION_SNAPSHOT_CV_COPY_FAILED',
                $exception->status,
                previous: $exception,
            );
        }

        try {
            $snapshot = $application->snapshot()->create([
                'schema_version' => ApplicationSnapshot::SCHEMA_VERSION,
                'profile_snapshot' => $profileSnapshot,
                'application_answers_snapshot' => $answersSnapshot,
                'source_cv_file_id' => $cvFile->id,
                'cv_original_name' => $cvFile->original_name,
                'cv_mime_type' => strtolower((string) $cvFile->mime_type),
                'cv_extension' => strtolower((string) $cvFile->extension),
                'cv_size_bytes' => $stored['size'],
                'cv_checksum_sha256' => $stored['checksum'],
                'cv_disk' => $stored['disk'],
                'cv_stored_path' => $stored['path'],
                'origin' => $origin,
                'accuracy' => $accuracy,
                'captured_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->cleanupFile($stored['disk'], $stored['path'], $application->id);
            throw $exception;
        }

        $this->auditLogService->record(
            $origin === ApplicationSnapshot::ORIGIN_BACKFILL
                ? 'application.snapshot_backfilled'
                : 'application.snapshot_created',
            $actor,
            ApplicationSnapshot::class,
            $snapshot->id,
            null,
            ['job_application_id' => $application->id, 'schema_version' => $snapshot->schema_version],
            ['origin' => $origin, 'accuracy' => $accuracy],
        );
        $this->auditLogService->record(
            'application.snapshot_file_copied',
            $actor,
            ApplicationSnapshot::class,
            $snapshot->id,
            null,
            ['checksum_sha256' => $snapshot->cv_checksum_sha256, 'size_bytes' => $snapshot->cv_size_bytes],
            ['job_application_id' => $application->id, 'source_cv_file_id' => $cvFile->id],
        );

        return $snapshot;
    }

    public function cleanupSnapshotFile(ApplicationSnapshot $snapshot): void
    {
        $this->cleanupFile($snapshot->cv_disk, $snapshot->cv_stored_path, $snapshot->job_application_id);
    }

    /**
     * @return array{disk: string, path: string, size: int, checksum: string}
     */
    private function copyCV(JobApplication $application, CVFile $cvFile): array
    {
        try {
            $this->cvAccess->assertDownloadable($cvFile);
        } catch (CVLifecycleException $exception) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_CV_UNAVAILABLE'),
                'APPLICATION_SNAPSHOT_CV_UNAVAILABLE',
                $exception->status,
                previous: $exception,
            );
        }

        $sourceSize = $this->privateStorage->size($cvFile->disk, $cvFile->stored_path);
        if ($sourceSize <= 0) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_CV_UNAVAILABLE'),
                'APPLICATION_SNAPSHOT_CV_UNAVAILABLE',
                422,
            );
        }

        $path = $this->privateStorage->objectPath(
            "application-snapshots/{$application->id}/cv",
            $cvFile->extension,
        );
        $stream = $this->privateStorage->readStream($cvFile->disk, $cvFile->stored_path);

        try {
            $this->privateStorage->storeStream(
                stream: $stream,
                prefix: "application-snapshots/{$application->id}/cv",
                extension: $cvFile->extension,
                mimeType: strtolower((string) $cvFile->mime_type),
                expectedSize: $sourceSize,
                disk: $cvFile->disk,
                path: $path,
            );
        } finally {
            fclose($stream);
        }

        try {
            $sourceChecksum = $this->privateStorage->checksum($cvFile->disk, $cvFile->stored_path);
            $targetChecksum = $this->privateStorage->checksum($cvFile->disk, $path);
            if (! hash_equals($sourceChecksum, $targetChecksum)) {
                throw new ApplicationSnapshotException(
                    __('domain_errors.APPLICATION_SNAPSHOT_CHECKSUM_MISMATCH'),
                    'APPLICATION_SNAPSHOT_CHECKSUM_MISMATCH',
                    409,
                );
            }
        } catch (Throwable $exception) {
            $this->cleanupFile($cvFile->disk, $path, $application->id);
            throw $exception;
        }

        return [
            'disk' => $cvFile->disk,
            'path' => $path,
            'size' => $sourceSize,
            'checksum' => $targetChecksum,
        ];
    }

    /** @return array<string, mixed> */
    private function profileSnapshot(JobSeekerProfile $profile): array
    {
        $profile->loadMissing(['user', 'city', 'experiences', 'education', 'skills']);

        return [
            'identity' => [
                'user_id' => $profile->user_id,
                'profile_id' => $profile->id,
                'name' => $profile->user?->name,
                'email' => $profile->user?->email,
                'phone' => $profile->phone,
                'headline' => $profile->headline,
                'summary' => $profile->summary,
            ],
            'location' => [
                'location_text' => $profile->location,
                'city' => $profile->city === null ? null : [
                    'id' => $profile->city->id,
                    'code' => $profile->city->code,
                    'country_code' => $profile->city->country_code,
                    'name_ar' => $profile->city->name_ar,
                    'name_en' => $profile->city->name_en,
                ],
            ],
            'availability' => [
                'status' => $profile->availability_status?->value,
                'available_from' => $profile->available_from?->toDateString(),
            ],
            'professional_links' => [
                'portfolio_url' => $profile->portfolio_url,
                'linkedin_url' => $profile->linkedin_url,
                'github_url' => $profile->github_url,
            ],
            'experiences' => $profile->experiences->map(static fn ($experience): array => [
                'id' => $experience->id,
                'job_title' => $experience->title,
                'company_name' => $experience->company_name,
                'location' => $experience->location,
                'start_date' => $experience->start_date?->toDateString(),
                'end_date' => $experience->end_date?->toDateString(),
                'is_current' => $experience->is_current,
                'description' => $experience->description,
                'source_type' => $experience->source_type,
                'source_cv_file_id' => $experience->source_cv_file_id,
                'user_verified_at' => $experience->user_verified_at?->toISOString(),
            ])->values()->all(),
            'education' => $profile->education->map(static fn ($education): array => [
                'id' => $education->id,
                'institution' => $education->institution,
                'degree' => $education->degree,
                'field_of_study' => $education->field_of_study,
                'start_date' => $education->start_date?->toDateString(),
                'end_date' => $education->end_date?->toDateString(),
                'description' => $education->description,
                'source_type' => $education->source_type,
                'source_cv_file_id' => $education->source_cv_file_id,
                'user_verified_at' => $education->user_verified_at?->toISOString(),
            ])->values()->all(),
            'skills' => $profile->skills->map(static fn ($skill): array => [
                'id' => $skill->id,
                'name' => $skill->name,
                'slug' => $skill->slug,
                'source_type' => $skill->pivot?->source_type,
                'source_cv_file_id' => $skill->pivot?->source_cv_file_id,
                'user_verified_at' => $skill->pivot?->user_verified_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, array{question: mixed, answered: bool, text_value: ?string, number_value: int|float|null, boolean_value: ?bool, selected_option_ids: array<int, int>}>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function answersSnapshot(array $plan): array
    {
        return array_map(static function (array $item): array {
            $question = $item['question'];
            $selectedIds = $item['selected_option_ids'];
            $type = $question->question_type instanceof ScreeningQuestionType
                ? $question->question_type->value
                : (string) $question->question_type;

            return [
                'source_question_id' => $question->id,
                'question_text' => $question->question_text,
                'question_type' => $type,
                'is_required' => (bool) $question->is_required,
                'sort_order' => (int) $question->sort_order,
                'options' => $question->options->map(static fn ($option): array => [
                    'source_option_id' => $option->id,
                    'option_text' => $option->option_text,
                    'sort_order' => (int) $option->sort_order,
                ])->values()->all(),
                'answer' => [
                    'answered' => $item['answered'],
                    'value' => $item['text_value'] ?? $item['number_value'] ?? $item['boolean_value'],
                    'selected_options' => $question->options
                        ->whereIn('id', $selectedIds)
                        ->map(static fn ($option): array => [
                            'source_option_id' => $option->id,
                            'option_text' => $option->option_text,
                        ])->values()->all(),
                ],
            ];
        }, $plan);
    }

    /** @return array<int|string, mixed> */
    private function backfilledAnswersSnapshot(JobApplication $application): array
    {
        if ($application->screeningQuestionSnapshots->isEmpty()) {
            return $application->screening_answers ?? [];
        }

        return $application->screeningQuestionSnapshots->map(static function ($question): array {
            $answer = $question->answer;
            $value = match ($question->question_type) {
                ScreeningQuestionType::SHORT_TEXT, ScreeningQuestionType::LONG_TEXT => $answer?->text_value,
                ScreeningQuestionType::NUMBER => $answer?->number_value === null ? null : (float) $answer->number_value,
                ScreeningQuestionType::BOOLEAN => $answer?->boolean_value,
                ScreeningQuestionType::SINGLE_CHOICE, ScreeningQuestionType::MULTIPLE_CHOICE => null,
            };

            $selected = $answer?->selectedOptions
                ->filter(static fn ($selection): bool => $selection->option !== null)
                ->map(static fn ($selection): array => [
                    'source_option_id' => $selection->option->source_option_id,
                    'option_text' => $selection->option->option_text,
                ])->values()->all() ?? [];

            return [
                'source_question_id' => $question->source_question_id,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type->value,
                'is_required' => $question->is_required,
                'sort_order' => $question->sort_order,
                'options' => $question->options->map(static fn ($option): array => [
                    'source_option_id' => $option->source_option_id,
                    'option_text' => $option->option_text,
                    'sort_order' => $option->sort_order,
                ])->values()->all(),
                'answer' => [
                    'answered' => $answer !== null,
                    'value' => $value,
                    'selected_options' => $selected,
                ],
            ];
        })->values()->all();
    }

    private function cleanupFile(string $disk, string $path, int $applicationId): void
    {
        try {
            $this->privateStorage->delete($disk, $path);
        } catch (Throwable $exception) {
            $this->privateStorage->logCleanupFailure(
                'application_snapshot_cleanup',
                $disk,
                $path,
                $exception,
                JobApplication::class,
                $applicationId,
            );
        }
    }
}
