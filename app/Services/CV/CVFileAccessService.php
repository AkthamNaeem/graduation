<?php

namespace App\Services\CV;

use App\Exceptions\CVLifecycleException;
use App\Models\CVFile;
use App\Services\PrivateFileStorageService;
use Throwable;

class CVFileAccessService
{
    public const PDF_MIME = 'application/pdf';

    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        private readonly PrivateFileStorageService $privateStorage,
    ) {}

    /** @return array{preview: bool, download: bool} */
    public function capabilities(CVFile $cvFile): array
    {
        try {
            $this->assertReadable($cvFile);
        } catch (Throwable) {
            return ['preview' => false, 'download' => false];
        }

        return [
            'preview' => $this->isPdf($cvFile),
            'download' => $this->isSupportedDownload($cvFile),
        ];
    }

    public function assertPreviewable(CVFile $cvFile): void
    {
        $this->assertReadable($cvFile);

        if (! $this->isPdf($cvFile)) {
            throw new CVLifecycleException(
                __('domain_errors.CV_PREVIEW_NOT_SUPPORTED'),
                'CV_PREVIEW_NOT_SUPPORTED',
                415,
                ['file' => [__('cv.preview_not_supported_hint')]],
                ['allowed_actions' => ['download']],
            );
        }
    }

    public function assertDownloadable(CVFile $cvFile): void
    {
        $this->assertReadable($cvFile);

        if (! $this->isSupportedDownload($cvFile)) {
            $this->unavailable();
        }
    }

    public function assertReadable(CVFile $cvFile): void
    {
        if (! $this->hasSafeStorageReference($cvFile)) {
            $this->unavailable();
        }

        if (! $this->privateStorage->exists($cvFile->disk, $cvFile->stored_path)) {
            throw new CVLifecycleException(
                __('domain_errors.CV_FILE_NOT_FOUND'),
                'CV_FILE_NOT_FOUND',
                404,
            );
        }

        if ((int) $cvFile->size_bytes <= 0
            || $this->privateStorage->size($cvFile->disk, $cvFile->stored_path) <= 0) {
            $this->unavailable();
        }
    }

    public function isPdf(CVFile $cvFile): bool
    {
        return strtolower((string) $cvFile->mime_type) === self::PDF_MIME
            && strtolower((string) $cvFile->extension) === 'pdf';
    }

    private function isSupportedDownload(CVFile $cvFile): bool
    {
        if ($this->isPdf($cvFile)) {
            return true;
        }

        return strtolower((string) $cvFile->extension) === 'docx'
            && in_array(strtolower((string) $cvFile->mime_type), [self::DOCX_MIME, 'application/zip'], true);
    }

    private function hasSafeStorageReference(CVFile $cvFile): bool
    {
        $path = str_replace('\\', '/', (string) $cvFile->stored_path);

        return filled($cvFile->disk)
            && $path !== ''
            && ! str_starts_with($path, '/')
            && preg_match('#(^|/)\.\.(/|$)#', $path) !== 1
            && ! str_contains($path, "\0");
    }

    private function unavailable(): never
    {
        throw new CVLifecycleException(
            __('domain_errors.CV_FILE_UNAVAILABLE'),
            'CV_FILE_UNAVAILABLE',
            422,
        );
    }
}
