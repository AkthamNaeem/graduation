<?php

namespace App\Jobs;

use App\Exceptions\CVParserException;
use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Services\AuditLogService;
use App\Services\CV\CVReviewDraftService;
use App\Services\CV\ProfileDataStateService;
use App\Services\CVParsingService;
use App\Services\PrivateFileStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ParseCVFileJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CVFile $cvFile,
    ) {}

    public function handle(
        CVParsingService $cvParsingService,
        ?AuditLogService $auditLogService = null,
        ?PrivateFileStorageService $privateStorage = null,
        ?ProfileDataStateService $profileDataStateService = null,
        ?CVReviewDraftService $reviewDraftService = null,
    ): void {
        $auditLogService ??= app(AuditLogService::class);
        $privateStorage ??= app(PrivateFileStorageService::class);
        $profileDataStateService ??= app(ProfileDataStateService::class);
        $reviewDraftService ??= app(CVReviewDraftService::class);
        $this->cvFile->refresh();
        if ($this->cvFile->archived_at !== null) {
            return;
        }

        $this->cvFile->forceFill([
            'status' => 'processing',
            'error_message' => null,
        ])->save();
        $auditLogService->record('cv.parsing_started', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
            'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
            'parsing_status' => 'processing', 'actor_id' => $this->cvFile->user_id,
        ]);

        $temporaryPath = null;
        try {
            $stream = $privateStorage->readStream($this->cvFile->disk, $this->cvFile->stored_path);
            $basePath = tempnam(sys_get_temp_dir(), 'private-cv-');
            if ($basePath === false) {
                fclose($stream);
                throw new \RuntimeException('A temporary parsing file could not be created.');
            }
            $temporaryPath = $basePath.'.'.strtolower($this->cvFile->extension);
            if (! rename($basePath, $temporaryPath)) {
                fclose($stream);
                @unlink($basePath);
                throw new \RuntimeException('A temporary parsing file could not be prepared.');
            }
            $target = fopen($temporaryPath, 'wb');
            if (! is_resource($target)) {
                fclose($stream);
                throw new \RuntimeException('A temporary parsing file could not be opened.');
            }
            try {
                if (stream_copy_to_stream($stream, $target) === false) {
                    throw new \RuntimeException('The CV could not be copied for parsing.');
                }
            } finally {
                fclose($stream);
                fclose($target);
            }

            $path = $temporaryPath;
            $rawText = $cvParsingService->extractText($path);
            $parsedJson = $cvParsingService->parseText($rawText);

            DB::transaction(function () use ($rawText, $parsedJson, $profileDataStateService, $reviewDraftService): void {
                $cvFile = CVFile::query()->lockForUpdate()->findOrFail($this->cvFile->id);
                $result = CVParsingResult::query()->firstOrCreate(
                    ['cv_file_id' => $cvFile->id],
                    ['raw_text' => $rawText, 'parsed_json' => $parsedJson],
                );

                $state = ['status' => 'parsed', 'error_message' => null];
                if ($cvFile->review_mode === null) {
                    $profile = $cvFile->user->jobSeekerProfile()->lockForUpdate()->first();
                    $hasData = $profile === null || $profileDataStateService->hasMeaningfulData($profile);
                    $state['review_mode'] = $hasData ? CVFile::REVIEW_MODE_PROFILE_SYNC : CVFile::REVIEW_MODE_INITIAL_IMPORT;
                    $state['review_status'] = $hasData ? CVFile::REVIEW_STATUS_COMPARISON_PENDING : CVFile::REVIEW_STATUS_DRAFT;
                    $result->forceFill([
                        'reviewed_json' => $hasData ? null : $reviewDraftService->build($result->parsed_json),
                        'reviewed_at' => $hasData ? null : now(),
                    ])->save();
                }

                $cvFile->forceFill($state)->save();
                $this->cvFile = $cvFile;
            });
            $auditLogService->record('cv.parsing_completed', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
                'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
                'parsing_status' => 'parsed', 'actor_id' => $this->cvFile->user_id,
                'parser_driver' => $parsedJson['_meta']['parser_driver'] ?? 'rules',
                'model' => $parsedJson['_meta']['model'] ?? null,
                'fallback_used' => $parsedJson['_meta']['fallback_used'] ?? false,
                'structured_output_mode' => $parsedJson['_meta']['structured_output_mode'] ?? null,
                'structured_output_fallback_reason' => $parsedJson['_meta']['structured_output_fallback_reason'] ?? null,
                'schema_version' => $parsedJson['_meta']['schema_version'] ?? '1.0',
                'normalization' => $parsedJson['_meta']['normalization'] ?? null,
            ]);
        } catch (Throwable $exception) {
            $this->cvFile->forceFill([
                'status' => 'failed',
                'error_message' => $exception instanceof CVParserException
                    ? $exception->reasonCode
                    : 'CV_PARSING_FAILED',
            ])->save();
            $auditLogService->record('cv.parsing_failed', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
                'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
                'parsing_status' => 'failed', 'actor_id' => $this->cvFile->user_id,
            ]);

            throw $exception;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
