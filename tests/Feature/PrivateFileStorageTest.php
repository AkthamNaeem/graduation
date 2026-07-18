<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ParseCVFileJob;
use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CVParsingService;
use App\Services\PrivateFileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class PrivateFileStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('s3');
        config(['filesystems.private_disk' => 's3']);
    }

    public function test_private_service_uses_selected_disk_uuid_keys_streams_and_private_download_headers(): void
    {
        $storage = app(PrivateFileStorageService::class);
        $file = UploadedFile::fake()->createWithContent('Jane Applicant Resume.pdf', 'private-cv-content');

        $stored = $storage->storeUploadedFile($file, 'cv-files');

        $this->assertSame('s3', $stored->disk);
        $this->assertMatchesRegularExpression('#^cv-files/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#', $stored->path);
        $this->assertStringNotContainsString('Jane', $stored->path);
        Storage::disk('s3')->assertExists($stored->path);
        $this->assertSame('private-cv-content', Storage::disk('s3')->get($stored->path));

        $response = $storage->downloadResponse('s3', $stored->path, "../Jane\r\nResume.pdf", 'application/pdf');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_storage_verification_command_cleans_up_its_object(): void
    {
        $this->artisan('storage:verify-private', ['--disk' => 'local'])
            ->expectsOutputToContain('verification passed')
            ->assertExitCode(0);

        $this->assertSame([], Storage::disk('local')->allFiles('health-checks'));
    }

    public function test_cv_upload_persists_selected_private_disk_without_exposing_storage_metadata(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::query()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->createWithContent('candidate.pdf', 'candidate cv'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.stored_path');

        $cv = CVFile::query()->findOrFail($response->json('data.id'));
        $this->assertSame('s3', $cv->disk);
        Storage::disk('s3')->assertExists($cv->stored_path);
        Queue::assertPushed(ParseCVFileJob::class, fn (ParseCVFileJob $job): bool => $job->cvFile->is($cv));
    }

    public function test_inventory_is_read_only_and_strict_mode_reports_missing_objects(): void
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        CVFile::query()->create($this->cvAttributes($user, 'missing/cv.pdf', 7));

        $this->artisan('storage:inventory-private-files', ['--disk' => 'local', '--format' => 'json', '--strict' => true])
            ->expectsOutputToContain('"missing_objects": 1')
            ->assertExitCode(1);

        $this->assertDatabaseHas('cv_files', ['user_id' => $user->id, 'disk' => 'local', 'stored_path' => 'missing/cv.pdf']);
    }

    public function test_migration_is_dry_by_default_verified_resumable_and_reversible(): void
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        Storage::disk('local')->put('legacy/resume.pdf', 'resume!');
        $cv = CVFile::query()->create($this->cvAttributes($user, 'legacy/resume.pdf', 7));

        $this->artisan('storage:migrate-private-files', ['--source' => 'local', '--target' => 's3', '--domain' => 'cv'])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
        $this->assertSame('local', $cv->refresh()->disk);
        $this->assertSame([], Storage::disk('s3')->allFiles());

        $this->artisan('storage:migrate-private-files', ['--source' => 'local', '--target' => 's3', '--domain' => 'cv', '--execute' => true])
            ->expectsOutputToContain('MIGRATED: 1')
            ->assertExitCode(0);
        $cv->refresh();
        $this->assertSame('s3', $cv->disk);
        $this->assertSame('resume!', Storage::disk('s3')->get($cv->stored_path));
        Storage::disk('local')->assertExists('legacy/resume.pdf');

        $this->artisan('storage:migrate-private-files', ['--source' => 'local', '--target' => 's3', '--domain' => 'cv', '--execute' => true])->assertExitCode(0);
        $this->assertCount(1, Storage::disk('s3')->allFiles());

        $this->artisan('storage:migrate-private-files', ['--source' => 's3', '--target' => 'local', '--domain' => 'cv', '--execute' => true, '--delete-source' => true])
            ->assertExitCode(0);
        $remotePath = $cv->stored_path;
        $cv->refresh();
        $this->assertSame('local', $cv->disk);
        Storage::disk('local')->assertExists($cv->stored_path);
        Storage::disk('s3')->assertMissing($remotePath);
    }

    public function test_missing_migration_source_does_not_update_database(): void
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        $cv = CVFile::query()->create($this->cvAttributes($user, 'missing/resume.pdf', 7));

        $this->artisan('storage:migrate-private-files', ['--source' => 'local', '--target' => 's3', '--domain' => 'cv', '--execute' => true])
            ->expectsOutputToContain('MISSING_SOURCE: 1')
            ->assertExitCode(1);

        $this->assertSame('local', $cv->refresh()->disk);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_migration_refuses_mismatched_existing_target(): void
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        Storage::disk('local')->put('legacy/resume.pdf', 'source!');
        $cv = CVFile::query()->create($this->cvAttributes($user, 'legacy/resume.pdf', 7));
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'cv|'.$cv->id.'|'.hash('sha256', 'legacy/resume.pdf'))->toString();
        $targetPath = 'cv-files/'.$cv->created_at->format('Y/m')."/{$uuid}.pdf";
        Storage::disk('s3')->put($targetPath, 'target!');

        $this->artisan('storage:migrate-private-files', ['--source' => 'local', '--target' => 's3', '--domain' => 'cv', '--execute' => true])
            ->expectsOutputToContain('TARGET_VERIFICATION_FAILED: 1')
            ->assertExitCode(1);

        $this->assertSame('local', $cv->refresh()->disk);
        $this->assertSame('target!', Storage::disk('s3')->get($targetPath));
    }

    public function test_cv_parser_reads_remote_disk_and_removes_temporary_copy(): void
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        Storage::disk('s3')->put('cv-files/remote.pdf', 'fake pdf');
        $cv = CVFile::query()->create([...$this->cvAttributes($user, 'cv-files/remote.pdf', 8), 'disk' => 's3']);
        $temporaryPath = null;
        $parser = Mockery::mock(CVParsingService::class);
        $parser->shouldReceive('extractText')->once()->withArgs(function (string $path) use (&$temporaryPath): bool {
            $temporaryPath = $path;

            return is_file($path) && str_ends_with($path, '.pdf');
        })->andReturn('Remote Candidate');
        $parser->shouldReceive('parseText')->once()->andReturn(['email' => null, 'phone' => null, 'skills' => [], 'experience' => [], 'education' => []]);

        (new ParseCVFileJob($cv))->handle($parser);

        $this->assertSame('parsed', $cv->refresh()->status);
        $this->assertNotNull($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
    }

    public function test_provider_write_failure_returns_safe_503_without_record_or_job(): void
    {
        Queue::fake();
        $invalidRoot = tempnam(sys_get_temp_dir(), 'invalid-storage-root-');
        config([
            'filesystems.private_disk' => 'broken',
            'filesystems.disks.broken' => ['driver' => 'local', 'root' => $invalidRoot, 'throw' => true, 'report' => false],
        ]);
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::query()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        try {
            $this->withToken($token)->post('/api/v1/cv/upload', [
                'file' => UploadedFile::fake()->createWithContent('resume.pdf', 'resume'),
            ], ['Accept' => 'application/json'])
                ->assertStatus(503)
                ->assertJsonPath('code', 'PRIVATE_FILE_WRITE_FAILED')
                ->assertJsonMissingPath('errors.exception');
        } finally {
            @unlink($invalidRoot);
        }

        $this->assertDatabaseCount('cv_files', 0);
        Queue::assertNothingPushed();
    }

    public function test_cv_database_failure_compensates_new_object_and_does_not_dispatch_parser(): void
    {
        Queue::fake();
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('forced database workflow failure'));
        $this->app->instance(AuditLogService::class, $audit);
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::query()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->post('/api/v1/cv/upload', [
            'file' => UploadedFile::fake()->createWithContent('resume.pdf', 'resume'),
        ], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertDatabaseCount('cv_files', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_missing_object_and_provider_read_failure_have_safe_distinct_responses(): void
    {
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::query()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;
        $missing = CVFile::query()->create($this->cvAttributes($user, 'missing/resume.pdf', 6));

        $this->withToken($token)->get("/api/v1/cv/{$missing->id}/download", ['Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJsonPath('code', 'CV_FILE_UNAVAILABLE');

        $invalidRoot = tempnam(sys_get_temp_dir(), 'invalid-read-root-');
        config(['filesystems.disks.broken' => ['driver' => 'local', 'root' => $invalidRoot, 'throw' => true, 'report' => false]]);
        $missing->forceFill(['disk' => 'broken'])->save();
        try {
            $this->withToken($token)->get("/api/v1/cv/{$missing->id}/download", ['Accept' => 'application/json'])
                ->assertStatus(503)
                ->assertJsonPath('code', 'PRIVATE_FILE_STORAGE_UNAVAILABLE')
                ->assertJsonMissingPath('errors.exception');
        } finally {
            @unlink($invalidRoot);
        }
    }

    private function cvAttributes(User $user, string $path, int $size): array
    {
        return [
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => $path,
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => $size,
            'status' => 'uploaded',
        ];
    }
}
