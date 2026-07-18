<?php

namespace App\Jobs;

use App\Models\CVFile;
use App\Models\CVParsingResult;
use App\Services\AuditLogService;
use App\Services\CVParsingService;
use App\Services\PrivateFileStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
    ): void {
        $auditLogService ??= app(AuditLogService::class);
        $privateStorage ??= app(PrivateFileStorageService::class);
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

            CVParsingResult::query()->updateOrCreate(
                ['cv_file_id' => $this->cvFile->id],
                [
                    'raw_text' => $rawText,
                    'parsed_json' => $parsedJson,
                ],
            );

            $this->cvFile->forceFill([
                'status' => 'parsed',
                'error_message' => null,
            ])->save();
            $auditLogService->record('cv.parsing_completed', $this->cvFile->user, CVFile::class, $this->cvFile->id, null, null, [
                'cv_file_id' => $this->cvFile->id, 'user_id' => $this->cvFile->user_id,
                'parsing_status' => 'parsed', 'actor_id' => $this->cvFile->user_id,
            ]);
        } catch (Throwable $exception) {
            $this->cvFile->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
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
