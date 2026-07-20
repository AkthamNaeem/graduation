<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobSkillRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_skill_contract_attaches_updates_and_deletes_without_duplicates(): void
    {
        [$employer, $job] = $this->scenario();
        $required = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $optional = Skill::create(['name' => 'Docker', 'slug' => 'docker']);
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/skills", ['skills' => [
            ['skill_id' => $required->id, 'requirement_type' => 'required'],
            ['skill_id' => $optional->id, 'requirement_type' => 'optional'],
        ]])->assertOk()
            ->assertJsonPath('data.skills.0.requirement_type', 'required')
            ->assertJsonPath('data.skills.1.requirement_type', 'optional');
        $this->assertDatabaseHas('audit_logs', ['action' => 'job.skills_updated', 'entity_id' => $job->id]);

        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/skills", ['skills' => [
            ['skill_id' => $optional->id, 'requirement_type' => 'required'],
        ]])->assertOk();
        $this->assertDatabaseCount('job_posting_skills', 2);
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $job->id,
            'skill_id' => $optional->id,
            'requirement_type' => 'required',
        ]);

        $this->withToken($token)->deleteJson("/api/v1/jobs/{$job->id}/skills/{$optional->id}")->assertOk();
        $this->assertDatabaseCount('job_posting_skills', 1);
    }

    public function test_publish_requires_at_least_one_required_skill_and_legacy_contract_defaults_required(): void
    {
        [$employer, $job] = $this->scenario();
        $optional = Skill::create(['name' => 'Docker', 'slug' => 'docker']);
        $required = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $token = $this->tokenFor($employer);

        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/skills", ['skills' => [[
            'skill_id' => $optional->id,
            'requirement_type' => 'optional',
        ]]])->assertOk();
        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/publish")
            ->assertUnprocessable()->assertJsonPath('code', 'JOB_REQUIRED_SKILL_MISSING');
        $this->assertSame('draft', $job->refresh()->status);

        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/skills", ['skill_ids' => [$required->id]])
            ->assertOk();
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $job->id,
            'skill_id' => $required->id,
            'requirement_type' => 'required',
        ]);
        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/publish")->assertOk();
    }

    public function test_create_update_filters_and_database_default_preserve_requirement_type(): void
    {
        [$employer, $job] = $this->scenario();
        $laravel = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $docker = Skill::create(['name' => 'Docker', 'slug' => 'docker']);
        $token = $this->tokenFor($employer);

        $createdId = $this->withToken($token)->postJson('/api/v1/jobs', [
            'title' => 'Structured Skill Role',
            'description' => 'Build APIs.',
            'requirements' => 'Laravel and API experience.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'work_mode' => 'remote',
            'skills' => [
                ['skill_id' => $laravel->id, 'requirement_type' => 'required'],
                ['skill_id' => $docker->id, 'requirement_type' => 'optional'],
            ],
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/jobs?skill=laravel&skill_requirement=required')
            ->assertOk()->assertJsonCount(0, 'data.data');
        JobPosting::query()->findOrFail($createdId)->forceFill(['status' => 'open', 'published_at' => now()])->save();
        $this->getJson('/api/v1/jobs?skill=laravel&skill_requirement=required')
            ->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/v1/jobs?skill=docker&skill_requirement=required')
            ->assertOk()->assertJsonCount(0, 'data.data');

        $this->withToken($token)->putJson("/api/v1/jobs/{$createdId}", ['skills' => [[
            'skill_id' => $docker->id,
            'requirement_type' => 'required',
        ]]])->assertOk()->assertJsonCount(1, 'data.skills');

        DB::table('job_posting_skills')->insert([
            'job_posting_id' => $job->id,
            'skill_id' => $laravel->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $job->id,
            'skill_id' => $laravel->id,
            'requirement_type' => 'required',
        ]);
    }

    public function test_duplicate_skill_ids_and_requirement_without_skill_filter_are_rejected(): void
    {
        [$employer, $job] = $this->scenario();
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/skills", ['skills' => [
            ['skill_id' => $skill->id, 'requirement_type' => 'required'],
            ['skill_id' => $skill->id, 'requirement_type' => 'optional'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['skills.1.skill_id']);
        $this->getJson('/api/v1/jobs?skill_requirement=required')
            ->assertUnprocessable()->assertJsonValidationErrors(['skill_requirement']);
    }

    private function scenario(): array
    {
        $company = Company::create(['name' => 'Skill Requirement Co.', 'approval_status' => 'approved']);
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Role',
            'description' => 'Build APIs.',
            'requirements' => 'Laravel and API experience.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'work_mode' => 'remote',
            'status' => 'draft',
        ]);

        return [$employer, $job];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
