<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CVFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateStorageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_leaves_the_database_and_both_disks_unchanged(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        $user = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        Storage::disk('local')->put('legacy/resume.pdf', 'resume!');
        $cv = CVFile::query()->create([
            'user_id' => $user->id,
            'original_name' => 'resume.pdf',
            'stored_path' => 'legacy/resume.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 7,
            'status' => 'uploaded',
        ]);

        $this->artisan('storage:migrate-private-files', [
            '--source' => 'local',
            '--target' => 's3',
            '--domain' => 'cv',
        ])->expectsOutputToContain('DRY RUN')->assertExitCode(0);

        $this->assertSame('local', $cv->refresh()->disk);
        Storage::disk('local')->assertExists('legacy/resume.pdf');
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }
}
