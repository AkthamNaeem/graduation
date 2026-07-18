<?php

namespace App\Console\Commands;

use App\Services\PrivateFileStorageService;
use Illuminate\Console\Command;
use Throwable;

class VerifyPrivateStorageCommand extends Command
{
    protected $signature = 'storage:verify-private {--disk=} {--prefix=health-checks}';

    protected $description = 'Verify private storage write, read, integrity, and delete operations';

    public function handle(PrivateFileStorageService $storage): int
    {
        $disk = (string) ($this->option('disk') ?: $storage->privateDisk());
        $path = null;
        $payload = 'private-storage-check:'.bin2hex(random_bytes(16));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $payload);
        rewind($stream);

        try {
            $file = $storage->storeStream($stream, (string) $this->option('prefix'), 'txt', 'text/plain', strlen($payload), $disk);
            $path = $file->path;
            $read = $storage->readStream($disk, $path);
            try {
                if (! hash_equals($payload, stream_get_contents($read))) {
                    throw new \RuntimeException('Private storage returned different content.');
                }
            } finally {
                fclose($read);
            }
            $storage->delete($disk, $path);
            $path = null;
            $this->info("Private storage verification passed for disk [{$disk}].");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error("Private storage verification failed for disk [{$disk}].");

            return self::FAILURE;
        } finally {
            fclose($stream);
            if ($path !== null) {
                try {
                    $storage->delete($disk, $path);
                } catch (Throwable) {
                    $this->warn('Verification cleanup failed; inspect structured application logs.');
                }
            }
        }
    }
}
