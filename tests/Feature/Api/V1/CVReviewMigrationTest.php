<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CVReviewMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_migrations_backfill_legacy_rows_and_roll_back_cleanly(): void
    {
        $reviewStateMigration = require database_path('migrations/2026_07_19_000001_add_review_fields_to_cv_files.php');
        $reviewedJsonMigration = require database_path('migrations/2026_07_19_000002_add_reviewed_json_to_cv_parsing_results.php');

        $reviewedJsonMigration->down();
        $reviewStateMigration->down();

        $this->assertFalse(Schema::hasColumn('cv_files', 'review_mode'));
        $this->assertFalse(Schema::hasColumn('cv_files', 'review_status'));
        $this->assertFalse(Schema::hasColumn('cv_parsing_results', 'reviewed_json'));
        $this->assertFalse(Schema::hasColumn('cv_parsing_results', 'reviewed_at'));

        $user = User::factory()->create();
        $pendingId = $this->insertLegacyCV($user->id, null);
        $confirmedId = $this->insertLegacyCV($user->id, now());
        DB::table('cv_parsing_results')->insert([
            'cv_file_id' => $pendingId,
            'raw_text' => 'legacy raw text',
            'parsed_json' => json_encode(['skills' => ['PHP']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reviewStateMigration->up();
        $reviewedJsonMigration->up();

        $this->assertTrue(Schema::hasColumn('cv_files', 'review_mode'));
        $this->assertTrue(Schema::hasColumn('cv_files', 'review_status'));
        $this->assertTrue(Schema::hasColumn('cv_parsing_results', 'reviewed_json'));
        $this->assertTrue(Schema::hasColumn('cv_parsing_results', 'reviewed_at'));
        $this->assertSame('profile_sync', DB::table('cv_files')->where('id', $pendingId)->value('review_mode'));
        $this->assertSame('comparison_pending', DB::table('cv_files')->where('id', $pendingId)->value('review_status'));
        $this->assertSame('applied', DB::table('cv_files')->where('id', $confirmedId)->value('review_status'));
        $this->assertNull(DB::table('cv_parsing_results')->where('cv_file_id', $pendingId)->value('reviewed_json'));
        $this->assertSame(['skills' => ['PHP']], json_decode(DB::table('cv_parsing_results')->where('cv_file_id', $pendingId)->value('parsed_json'), true));
        $this->assertContains('cv_review_state_idx', collect(Schema::getIndexes('cv_files'))->pluck('name')->all());

        $reviewedJsonMigration->down();
        $reviewStateMigration->down();

        $this->assertFalse(Schema::hasColumn('cv_files', 'review_mode'));
        $this->assertFalse(Schema::hasColumn('cv_files', 'review_status'));
        $this->assertFalse(Schema::hasColumn('cv_parsing_results', 'reviewed_json'));
        $this->assertFalse(Schema::hasColumn('cv_parsing_results', 'reviewed_at'));
        $this->assertSame('legacy.pdf', DB::table('cv_files')->where('id', $pendingId)->value('original_name'));
        $this->assertSame(['skills' => ['PHP']], json_decode(DB::table('cv_parsing_results')->where('cv_file_id', $pendingId)->value('parsed_json'), true));

        // Restore the migrated schema for RefreshDatabase teardown and any following tests.
        $reviewStateMigration->up();
        $reviewedJsonMigration->up();
    }

    private function insertLegacyCV(int $userId, mixed $confirmedAt): int
    {
        return DB::table('cv_files')->insertGetId([
            'user_id' => $userId,
            'original_name' => 'legacy.pdf',
            'stored_path' => 'cv-files/legacy.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'status' => 'parsed',
            'error_message' => null,
            'confirmed_at' => $confirmedAt,
            'archived_at' => null,
            'version_label' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
