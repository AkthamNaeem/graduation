<?php

namespace Tests\Feature;

use App\Services\PrivateFileStorageService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('s3-integration')]
class S3PrivateStorageIntegrationTest extends TestCase
{
    public function test_real_s3_compatible_private_storage_lifecycle(): void
    {
        if (! filter_var(env('RUN_S3_INTEGRATION_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_S3_INTEGRATION_TESTS=true with a dedicated test bucket to run this test.');
        }

        config(['filesystems.disks.s3-integration' => [
            'driver' => 's3',
            'key' => env('S3_TEST_ACCESS_KEY'),
            'secret' => env('S3_TEST_SECRET_KEY'),
            'region' => env('S3_TEST_REGION'),
            'bucket' => env('S3_TEST_BUCKET'),
            'endpoint' => env('S3_TEST_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(env('S3_TEST_PATH_STYLE', false), FILTER_VALIDATE_BOOL),
            'visibility' => 'private',
            'throw' => true,
        ]]);

        $storage = app(PrivateFileStorageService::class);
        $prefix = 'integration-checks/'.bin2hex(random_bytes(8));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'cross-process-durable-object');
        rewind($stream);
        $file = null;
        try {
            $file = $storage->storeStream($stream, $prefix, 'txt', 'text/plain', 28, 's3-integration');
            $this->assertTrue($storage->exists($file->disk, $file->path));
            $this->assertSame(28, $storage->size($file->disk, $file->path));
            $this->assertSame(hash('sha256', 'cross-process-durable-object'), $storage->checksum($file->disk, $file->path));
        } finally {
            fclose($stream);
            if ($file !== null) {
                $storage->delete($file->disk, $file->path);
                $this->assertFalse(Storage::disk($file->disk)->exists($file->path));
            }
        }
    }
}
