<?php

namespace App\Services;

use App\Exceptions\ApplicationSnapshotException;
use App\Models\ApplicationSnapshot;
use App\Models\JobApplication;
use App\Services\CV\CVFileAccessService;

class ApplicationSnapshotCVAccessService
{
    public function __construct(private readonly PrivateFileStorageService $privateStorage) {}

    public function snapshotFor(JobApplication $application): ApplicationSnapshot
    {
        $snapshot = $application->snapshot()->first();
        if (! $snapshot instanceof ApplicationSnapshot) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_NOT_AVAILABLE'),
                'APPLICATION_SNAPSHOT_NOT_AVAILABLE',
                404,
            );
        }

        return $snapshot;
    }

    public function assertDownloadable(ApplicationSnapshot $snapshot): void
    {
        $this->assertReadable($snapshot);

        $mime = strtolower($snapshot->cv_mime_type);
        $extension = strtolower($snapshot->cv_extension);
        $supported = ($mime === CVFileAccessService::PDF_MIME && $extension === 'pdf')
            || ($extension === 'docx' && in_array($mime, [CVFileAccessService::DOCX_MIME, 'application/zip'], true));

        if (! $supported) {
            $this->unavailable(415);
        }
    }

    public function assertPreviewable(ApplicationSnapshot $snapshot): void
    {
        $this->assertReadable($snapshot);

        if (strtolower($snapshot->cv_mime_type) !== CVFileAccessService::PDF_MIME
            || strtolower($snapshot->cv_extension) !== 'pdf') {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_CV_PREVIEW_NOT_SUPPORTED'),
                'APPLICATION_SNAPSHOT_CV_PREVIEW_NOT_SUPPORTED',
                415,
                ['file' => [__('cv.preview_not_supported_hint')]],
                ['allowed_actions' => ['download']],
            );
        }
    }

    private function assertReadable(ApplicationSnapshot $snapshot): void
    {
        $path = str_replace('\\', '/', $snapshot->cv_stored_path);
        if ($snapshot->cv_disk === '' || $path === '' || str_starts_with($path, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || str_contains($path, "\0")) {
            $this->unavailable();
        }
        if (! $this->privateStorage->exists($snapshot->cv_disk, $snapshot->cv_stored_path)) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_CV_NOT_FOUND'),
                'APPLICATION_SNAPSHOT_CV_NOT_FOUND',
                404,
            );
        }
        if ($snapshot->cv_size_bytes <= 0
            || $this->privateStorage->size($snapshot->cv_disk, $snapshot->cv_stored_path) !== $snapshot->cv_size_bytes) {
            $this->unavailable();
        }
        if (! hash_equals(
            $snapshot->cv_checksum_sha256,
            $this->privateStorage->checksum($snapshot->cv_disk, $snapshot->cv_stored_path),
        )) {
            throw new ApplicationSnapshotException(
                __('domain_errors.APPLICATION_SNAPSHOT_CHECKSUM_MISMATCH'),
                'APPLICATION_SNAPSHOT_CHECKSUM_MISMATCH',
                409,
            );
        }
    }

    private function unavailable(int $status = 422): never
    {
        throw new ApplicationSnapshotException(
            __('domain_errors.APPLICATION_SNAPSHOT_CV_UNAVAILABLE'),
            'APPLICATION_SNAPSHOT_CV_UNAVAILABLE',
            $status,
        );
    }
}
