<?php

namespace App\Console\Commands;

use App\Services\PrivateFileRecordService;
use App\Services\PrivateFileStorageService;
use Illuminate\Console\Command;
use Throwable;

class InventoryPrivateFilesCommand extends Command
{
    protected $signature = 'storage:inventory-private-files {--disk=} {--format=table} {--output=} {--strict}';

    protected $description = 'Read-only inventory of private file database records and objects';

    public function handle(PrivateFileRecordService $records, PrivateFileStorageService $storage): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error('Format must be table, json, or csv.');

            return self::INVALID;
        }

        $summary = ['total_records' => 0, 'by_disk' => [], 'existing_objects' => 0, 'missing_objects' => 0, 'size_mismatches' => 0, 'unsupported_disks' => 0];
        $details = [];
        foreach ($records->definitions() as $domain => $definition) {
            $records->query($definition, $this->option('disk') ?: null)->orderBy('id')->chunkById(100, function ($models) use (&$summary, &$details, $domain, $definition, $records, $storage): void {
                foreach ($models as $record) {
                    $value = $records->values($record, $definition);
                    $summary['total_records']++;
                    $summary['by_disk'][$value['disk']] = ($summary['by_disk'][$value['disk']] ?? 0) + 1;
                    $status = 'EXISTS';
                    $actualSize = null;
                    if (config("filesystems.disks.{$value['disk']}") === null) {
                        $summary['unsupported_disks']++;
                        $status = 'UNSUPPORTED_DISK';
                    } else {
                        try {
                            if (! $storage->exists($value['disk'], $value['path'])) {
                                $summary['missing_objects']++;
                                $status = 'MISSING';
                            } else {
                                $summary['existing_objects']++;
                                $actualSize = $storage->size($value['disk'], $value['path']);
                                if ($actualSize !== $value['size']) {
                                    $summary['size_mismatches']++;
                                    $status = 'SIZE_MISMATCH';
                                }
                            }
                        } catch (Throwable) {
                            $summary['missing_objects']++;
                            $status = 'PROVIDER_UNAVAILABLE';
                        }
                    }
                    $details[] = ['domain' => $domain, 'record_id' => $record->getKey(), 'disk' => $value['disk'], 'path_hash' => hash('sha256', $value['path']), 'expected_size' => $value['size'], 'actual_size' => $actualSize, 'status' => $status];
                }
            });
        }

        $output = $this->renderOutput($format, $summary, $details);
        if ($this->option('output')) {
            file_put_contents((string) $this->option('output'), $output);
            $this->info('Private file inventory written to the requested output file.');
        } else {
            $this->line($output);
        }

        $problems = $summary['missing_objects'] + $summary['size_mismatches'] + $summary['unsupported_disks'];

        return $this->option('strict') && $problems > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function renderOutput(string $format, array $summary, array $details): string
    {
        if ($format === 'json') {
            return json_encode(['summary' => $summary, 'records' => $details], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+b');
            fputcsv($stream, ['domain', 'record_id', 'disk', 'path_hash', 'expected_size', 'actual_size', 'status']);
            foreach ($details as $row) {
                fputcsv($stream, $row);
            }
            rewind($stream);

            return stream_get_contents($stream);
        }

        $lines = [sprintf('total_records=%d existing_objects=%d missing_objects=%d size_mismatches=%d unsupported_disks=%d', $summary['total_records'], $summary['existing_objects'], $summary['missing_objects'], $summary['size_mismatches'], $summary['unsupported_disks'])];
        foreach ($summary['by_disk'] as $disk => $count) {
            $lines[] = "disk={$disk} records={$count}";
        }

        return implode(PHP_EOL, $lines);
    }
}
