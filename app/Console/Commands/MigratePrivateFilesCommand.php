<?php

namespace App\Console\Commands;

use App\Exceptions\PrivateFileStorageException;
use App\Services\PrivateFileRecordService;
use App\Services\PrivateFileStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

class MigratePrivateFilesCommand extends Command
{
    protected $signature = 'storage:migrate-private-files
        {--source=local}
        {--target=s3}
        {--domain=all}
        {--batch=100}
        {--limit=}
        {--delete-source}
        {--report=}
        {--execute}';

    protected $description = 'Safely migrate private file objects between configured disks (dry-run by default)';

    public function handle(PrivateFileRecordService $records, PrivateFileStorageService $storage): int
    {
        $source = (string) $this->option('source');
        $target = (string) $this->option('target');
        $batch = max(1, min(1000, (int) $this->option('batch')));
        $limit = $this->option('limit') === null ? null : max(0, (int) $this->option('limit'));
        $execute = (bool) $this->option('execute');
        if ($source === $target || config("filesystems.disks.{$source}") === null || config("filesystems.disks.{$target}") === null) {
            $this->error('Source and target must be different configured disks.');

            return self::INVALID;
        }

        try {
            $definitions = $records->selected((string) $this->option('domain'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $results = [];
        $processed = 0;
        foreach ($definitions as $domain => $definition) {
            $query = $records->query($definition, $source)->orderBy('id');
            foreach ($query->lazyById($batch) as $record) {
                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }
                $processed++;
                $results[] = $this->migrateRecord($domain, $definition, $record, $source, $target, $execute, (bool) $this->option('delete-source'), $records, $storage);
                if ($processed % $batch === 0) {
                    gc_collect_cycles();
                }
            }
        }

        $this->writeReport($results, $this->option('report') ?: null);
        $counts = collect($results)->countBy('status')->sortKeys();
        foreach ($counts as $status => $count) {
            $this->line("{$status}: {$count}");
        }
        if (! $execute) {
            $this->comment('DRY RUN: no database rows or objects were changed. Use --execute only after inventory review.');
        }

        $failures = ['MISSING_SOURCE', 'TARGET_VERIFICATION_FAILED', 'SOURCE_READ_FAILED', 'TARGET_WRITE_FAILED', 'ROW_CHANGED', 'DB_UPDATE_FAILED', 'CLEANUP_FAILED'];

        return collect($results)->contains(fn (array $result): bool => in_array($result['status'], $failures, true)) ? self::FAILURE : self::SUCCESS;
    }

    private function migrateRecord(string $domain, array $definition, Model $record, string $source, string $target, bool $execute, bool $deleteSource, PrivateFileRecordService $records, PrivateFileStorageService $storage): array
    {
        $value = $records->values($record, $definition);
        $result = ['domain' => $domain, 'record_id' => $record->getKey(), 'source_disk' => $source, 'target_disk' => $target, 'path_hash' => hash('sha256', $value['path']), 'status' => 'DRY_RUN_READY'];
        try {
            if (! $storage->exists($source, $value['path'])) {
                return [...$result, 'status' => 'MISSING_SOURCE'];
            }
            if ($storage->size($source, $value['path']) !== $value['size']) {
                return [...$result, 'status' => 'SOURCE_READ_FAILED'];
            }
            if (! $execute) {
                return $result;
            }

            $targetPath = $this->targetPath($domain, $definition['prefix'], $record, $value['path'], $value['extension']);
            $targetAlreadyExisted = $storage->exists($target, $targetPath);
            if ($targetAlreadyExisted) {
                if ($storage->size($target, $targetPath) !== $value['size'] || ! hash_equals($storage->checksum($source, $value['path']), $storage->checksum($target, $targetPath))) {
                    return [...$result, 'status' => 'TARGET_VERIFICATION_FAILED'];
                }
                $status = 'TARGET_EXISTS_VERIFIED';
            } else {
                $storage->copyBetweenDisks($source, $value['path'], $target, $targetPath, $value['size']);
                $status = 'MIGRATED';
            }

            try {
                $updated = DB::transaction(function () use ($definition, $record, $source, $target, $value, $targetPath): bool {
                    $locked = $definition['model']::query()->lockForUpdate()->find($record->getKey());
                    if (! $locked instanceof Model
                        || $locked->getAttribute($definition['disk']) !== $source
                        || $locked->getAttribute($definition['path']) !== $value['path']) {
                        return false;
                    }
                    $locked->setAttribute($definition['disk'], $target);
                    $locked->setAttribute($definition['path'], $targetPath);
                    $locked->save();

                    return true;
                });
            } catch (Throwable $exception) {
                if (! $targetAlreadyExisted) {
                    $this->cleanupTarget($storage, $target, $targetPath);
                }

                return [...$result, 'status' => 'DB_UPDATE_FAILED'];
            }

            if (! $updated) {
                if (! $targetAlreadyExisted) {
                    $this->cleanupTarget($storage, $target, $targetPath);
                }

                return [...$result, 'status' => 'ROW_CHANGED'];
            }

            if ($deleteSource) {
                try {
                    $storage->delete($source, $value['path']);
                } catch (Throwable $exception) {
                    $storage->logCleanupFailure('private_file_migration_source_cleanup', $source, $value['path'], $exception, $definition['model'], $record->getKey());

                    return [...$result, 'status' => 'CLEANUP_FAILED'];
                }
            }

            return [...$result, 'status' => $status];
        } catch (PrivateFileStorageException $exception) {
            $status = match ($exception->errorCode) {
                'PRIVATE_FILE_READ_FAILED', 'PRIVATE_FILE_STORAGE_UNAVAILABLE' => 'SOURCE_READ_FAILED',
                'PRIVATE_FILE_VERIFICATION_FAILED' => 'TARGET_VERIFICATION_FAILED',
                default => 'TARGET_WRITE_FAILED',
            };

            return [...$result, 'status' => $status];
        }
    }

    private function targetPath(string $domain, string $prefix, Model $record, string $sourcePath, ?string $extension): string
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, $domain.'|'.$record->getKey().'|'.hash('sha256', $sourcePath))->toString();
        $date = $record->getAttribute('created_at') ?? now();
        $year = method_exists($date, 'format') ? $date->format('Y') : now()->format('Y');
        $month = method_exists($date, 'format') ? $date->format('m') : now()->format('m');
        $suffix = $extension ? '.'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $extension)) : '';

        return "{$prefix}/{$year}/{$month}/{$uuid}{$suffix}";
    }

    private function cleanupTarget(PrivateFileStorageService $storage, string $disk, string $path): void
    {
        try {
            $storage->delete($disk, $path);
        } catch (Throwable $exception) {
            $storage->logCleanupFailure('private_file_migration_target_cleanup', $disk, $path, $exception);
        }
    }

    private function writeReport(array $results, ?string $path): void
    {
        if ($path === null) {
            return;
        }
        $stream = fopen($path, 'wb');
        fputcsv($stream, ['domain', 'record_id', 'source_disk', 'target_disk', 'path_hash', 'status']);
        foreach ($results as $result) {
            fputcsv($stream, $result);
        }
        fclose($stream);
        $this->info('Migration report written.');
    }
}
