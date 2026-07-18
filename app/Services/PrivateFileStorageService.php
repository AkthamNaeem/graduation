<?php

namespace App\Services;

use App\Exceptions\PrivateFileStorageException;
use App\Support\StoredPrivateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PrivateFileStorageService
{
    public function privateDisk(): string
    {
        return (string) config('filesystems.private_disk', 'local');
    }

    public function storeUploadedFile(UploadedFile $file, string $prefix, ?string $disk = null): StoredPrivateFile
    {
        $stream = @fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            throw new PrivateFileStorageException('Private file storage is unavailable.', 'PRIVATE_FILE_WRITE_FAILED');
        }

        try {
            $physicalSize = (int) (fstat($stream)['size'] ?? $file->getSize());
            $stored = $this->storeStream(
                stream: $stream,
                prefix: $prefix,
                extension: strtolower($file->getClientOriginalExtension()) ?: null,
                mimeType: $file->getMimeType() ?: 'application/octet-stream',
                expectedSize: $physicalSize,
                disk: $disk,
            );

            return new StoredPrivateFile(
                $stored->disk,
                $stored->path,
                (int) $file->getSize(),
                $stored->mimeType,
                $stored->extension,
            );
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    public function storeStream($stream, string $prefix, ?string $extension, string $mimeType, int $expectedSize, ?string $disk = null, ?string $path = null): StoredPrivateFile
    {
        $disk ??= $this->privateDisk();
        $path ??= $this->objectPath($prefix, $extension);
        $written = false;

        try {
            $written = (bool) Storage::disk($disk)->writeStream($path, $stream, [
                'visibility' => 'private',
                'ContentType' => $mimeType,
            ]);
            if (! $written) {
                throw new PrivateFileStorageException('Private file storage is unavailable.', 'PRIVATE_FILE_WRITE_FAILED');
            }
            $this->verifyObject($disk, $path, $expectedSize);

            return new StoredPrivateFile($disk, $path, $expectedSize, $mimeType, $extension);
        } catch (PrivateFileStorageException $exception) {
            if ($written) {
                $this->cleanupFailedWrite($disk, $path);
            }
            $this->logFailure('write', $disk, $path, $exception, true);
            throw $exception;
        } catch (Throwable $exception) {
            if ($written) {
                $this->cleanupFailedWrite($disk, $path);
            }
            $this->logFailure('write', $disk, $path, $exception, true);
            throw new PrivateFileStorageException('Private file storage is unavailable.', 'PRIVATE_FILE_WRITE_FAILED', 503, $exception);
        }
    }

    public function exists(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable $exception) {
            $this->logFailure('exists', $disk, $path, $exception, true);
            throw new PrivateFileStorageException('Private file storage is unavailable.', 'PRIVATE_FILE_STORAGE_UNAVAILABLE', 503, $exception);
        }
    }

    public function size(string $disk, string $path): int
    {
        try {
            return (int) Storage::disk($disk)->size($path);
        } catch (Throwable $exception) {
            $this->logFailure('size', $disk, $path, $exception, true);
            throw new PrivateFileStorageException('The private file could not be read.', 'PRIVATE_FILE_READ_FAILED', 503, $exception);
        }
    }

    /** @return resource */
    public function readStream(string $disk, string $path)
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
            if (! is_resource($stream)) {
                throw new PrivateFileStorageException('The private file could not be read.', 'PRIVATE_FILE_READ_FAILED');
            }

            return $stream;
        } catch (PrivateFileStorageException $exception) {
            $this->logFailure('read', $disk, $path, $exception, true);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('read', $disk, $path, $exception, true);
            throw new PrivateFileStorageException('The private file could not be read.', 'PRIVATE_FILE_READ_FAILED', 503, $exception);
        }
    }

    public function downloadResponse(string $disk, string $path, string $originalName, ?string $mimeType = null): StreamedResponse
    {
        $stream = $this->readStream($disk, $path);
        $filename = basename(str_replace(["\r", "\n", "\0"], '', $originalName)) ?: 'download';

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function delete(string $disk, string $path): void
    {
        try {
            if (! Storage::disk($disk)->exists($path)) {
                return;
            }
            if (! Storage::disk($disk)->delete($path)) {
                throw new PrivateFileStorageException('The private file could not be deleted.', 'PRIVATE_FILE_DELETE_FAILED');
            }
        } catch (PrivateFileStorageException $exception) {
            $this->logFailure('delete', $disk, $path, $exception, true);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('delete', $disk, $path, $exception, true);
            throw new PrivateFileStorageException('The private file could not be deleted.', 'PRIVATE_FILE_DELETE_FAILED', 503, $exception);
        }
    }

    public function verifyObject(string $disk, string $path, ?int $expectedSize = null): void
    {
        try {
            if (! Storage::disk($disk)->exists($path)) {
                throw new PrivateFileStorageException('Private file verification failed.', 'PRIVATE_FILE_VERIFICATION_FAILED');
            }
            if ($expectedSize !== null && (int) Storage::disk($disk)->size($path) !== $expectedSize) {
                throw new PrivateFileStorageException('Private file verification failed.', 'PRIVATE_FILE_VERIFICATION_FAILED');
            }
        } catch (PrivateFileStorageException $exception) {
            $this->logFailure('verify', $disk, $path, $exception, true);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('verify', $disk, $path, $exception, true);
            throw new PrivateFileStorageException('Private file verification failed.', 'PRIVATE_FILE_VERIFICATION_FAILED', 503, $exception);
        }
    }

    public function copyBetweenDisks(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath, int $expectedSize): string
    {
        $source = $this->readStream($sourceDisk, $sourcePath);
        $sourceHash = hash_init('sha256');
        $hashingStream = fopen('php://temp', 'w+b');
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new PrivateFileStorageException('The private file could not be read.', 'PRIVATE_FILE_READ_FAILED');
                }
                hash_update($sourceHash, $chunk);
                fwrite($hashingStream, $chunk);
            }
            rewind($hashingStream);
            $this->storeStream($hashingStream, 'migration', pathinfo($targetPath, PATHINFO_EXTENSION) ?: null, 'application/octet-stream', $expectedSize, $targetDisk, $targetPath);
        } finally {
            fclose($source);
            fclose($hashingStream);
        }

        $target = $this->readStream($targetDisk, $targetPath);
        try {
            $targetHashContext = hash_init('sha256');
            hash_update_stream($targetHashContext, $target);
            $targetHash = hash_final($targetHashContext);
        } finally {
            fclose($target);
        }
        $sourceDigest = hash_final($sourceHash);
        if (! hash_equals($sourceDigest, $targetHash)) {
            try {
                $this->delete($targetDisk, $targetPath);
            } catch (Throwable) {
            }
            throw new PrivateFileStorageException('Private file verification failed.', 'PRIVATE_FILE_VERIFICATION_FAILED');
        }

        return $sourceDigest;
    }

    public function objectPath(string $prefix, ?string $extension = null, ?string $uuid = null): string
    {
        $cleanPrefix = trim($prefix, '/');
        if ($cleanPrefix === '' || str_contains($cleanPrefix, '..') || preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*$#', $cleanPrefix) !== 1) {
            throw new \InvalidArgumentException('The private storage prefix is invalid.');
        }
        $safeExtension = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $extension));
        $suffix = $safeExtension === '' ? '' : '.'.$safeExtension;

        return sprintf('%s/%s/%s/%s%s', $cleanPrefix, now()->format('Y'), now()->format('m'), $uuid ?? Str::uuid(), $suffix);
    }

    public function checksum(string $disk, string $path): string
    {
        $stream = $this->readStream($disk, $path);
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    public function logCleanupFailure(string $operation, string $disk, string $path, Throwable $exception, ?string $entityType = null, int|string|null $entityId = null): void
    {
        $this->logFailure($operation, $disk, $path, $exception, true, $entityType, $entityId);
    }

    private function logFailure(string $operation, string $disk, string $path, Throwable $exception, bool $retryable, ?string $entityType = null, int|string|null $entityId = null): void
    {
        Log::error('Private file storage operation failed.', [
            'operation' => $operation,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'disk_name' => $disk,
            'path_hash' => hash('sha256', $path),
            'exception_class' => $exception::class,
            'retryable' => $retryable,
        ]);
    }

    private function cleanupFailedWrite(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            $this->logFailure('failed_write_cleanup', $disk, $path, $exception, true);
        }
    }
}
