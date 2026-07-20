<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CandidateMatchingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_and_reapply_preserve_legacy_relationship_ids_and_unique_constraint(): void
    {
        $company = Company::factory()->create();
        $job = JobPosting::factory()->create(['company_id' => $company->id, 'education_level' => 'bachelor']);
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $job->skills()->attach($skill->id, ['requirement_type' => 'nice_to_have', 'weight' => 5]);
        $migration = require database_path('migrations/2026_07_20_000003_add_candidate_matching_fields.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('job_posting_skills', 'weight'));
        $this->assertFalse(Schema::hasColumn('job_postings', 'education_level'));
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $job->id,
            'skill_id' => $skill->id,
            'requirement_type' => 'optional',
        ]);
        try {
            DB::table('job_posting_skills')->insert([
                'job_posting_id' => $job->id,
                'skill_id' => $skill->id,
                'requirement_type' => 'required',
            ]);
            $this->fail('Expected the historical job/skill unique constraint to remain active.');
        } catch (QueryException) {
            $this->assertDatabaseCount('job_posting_skills', 1);
        }

        $migration->up();

        $this->assertTrue(Schema::hasColumn('job_posting_skills', 'weight'));
        $this->assertTrue(Schema::hasColumn('job_postings', 'education_level'));
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $job->id,
            'skill_id' => $skill->id,
            'requirement_type' => 'nice_to_have',
            'weight' => 1,
        ]);
    }
}
