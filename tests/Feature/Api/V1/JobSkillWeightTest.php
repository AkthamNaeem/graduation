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

class JobSkillWeightTest extends TestCase
{
    use RefreshDatabase;

    public function test_separated_contract_saves_weights_and_returns_separated_arrays(): void
    {
        [$employer, $job, $required, $nice] = $this->scenario();

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/skills", [
            'required_skills' => [['skill_id' => $required->id, 'weight' => 5]],
            'nice_to_have_skills' => [['skill_id' => $nice->id]],
        ])->assertOk()
            ->assertJsonPath('data.required_skills.0.weight', 5)
            ->assertJsonPath('data.required_skills.0.requirement_type', 'required')
            ->assertJsonPath('data.nice_to_have_skills.0.weight', 1)
            ->assertJsonPath('data.nice_to_have_skills.0.requirement_type', 'nice_to_have');

        $this->assertDatabaseHas('job_posting_skills', ['skill_id' => $required->id, 'weight' => 5]);
        $this->assertDatabaseHas('job_posting_skills', ['skill_id' => $nice->id, 'weight' => 1]);
    }

    public function test_job_create_and_update_accept_the_separated_contract(): void
    {
        [$employer, , $required, $nice] = $this->scenario();
        $token = $this->tokenFor($employer);
        $jobId = $this->withToken($token)->postJson('/api/v1/jobs', [
            'title' => 'Weighted API Engineer',
            'description' => 'Build APIs.',
            'requirements' => 'API experience.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid',
            'education_level' => 'bachelor',
            'work_mode' => 'remote',
            'required_skills' => [['skill_id' => $required->id, 'weight' => 5]],
            'nice_to_have_skills' => [['skill_id' => $nice->id, 'weight' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.education_level', 'bachelor')
            ->assertJsonCount(1, 'data.required_skills')
            ->assertJsonCount(1, 'data.nice_to_have_skills')
            ->json('data.id');

        $this->withToken($token)->putJson("/api/v1/jobs/{$jobId}", [
            'required_skills' => [['skill_id' => $nice->id, 'weight' => 4]],
            'nice_to_have_skills' => [],
        ])->assertOk()->assertJsonCount(1, 'data.skills');

        $this->assertDatabaseMissing('job_posting_skills', [
            'job_posting_id' => $jobId,
            'skill_id' => $required->id,
        ]);
        $this->assertDatabaseHas('job_posting_skills', [
            'job_posting_id' => $jobId,
            'skill_id' => $nice->id,
            'requirement_type' => 'required',
            'weight' => 4,
        ]);
    }

    public function test_skill_weight_and_type_can_be_updated_without_duplicate_pivot(): void
    {
        [$employer, $job, $skill] = $this->scenario();
        $token = $this->tokenFor($employer);
        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/skills", [
            'required_skills' => [['skill_id' => $skill->id, 'weight' => 2]],
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/skills", [
            'nice_to_have_skills' => [['skill_id' => $skill->id, 'weight' => 4]],
        ])->assertOk();

        $this->assertDatabaseCount('job_posting_skills', 1);
        $this->assertDatabaseHas('job_posting_skills', [
            'skill_id' => $skill->id,
            'requirement_type' => 'nice_to_have',
            'weight' => 4,
        ]);
    }

    public function test_invalid_required_weights_are_rejected(): void
    {
        [$employer, $job, $skill] = $this->scenario();
        $token = $this->tokenFor($employer);

        foreach ([0, 6, '2.5'] as $weight) {
            $this->withToken($token)->postJson("/api/v1/jobs/{$job->id}/skills", [
                'required_skills' => [['skill_id' => $skill->id, 'weight' => $weight]],
            ])->assertUnprocessable()->assertJsonValidationErrors(['required_skills.0.weight']);
        }
    }

    public function test_duplicate_across_types_is_rejected(): void
    {
        [$employer, $job, $skill] = $this->scenario();

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/skills", [
            'required_skills' => [['skill_id' => $skill->id, 'weight' => 1]],
            'nice_to_have_skills' => [['skill_id' => $skill->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['nice_to_have_skills']);

        $this->assertDatabaseCount('job_posting_skills', 0);
    }

    public function test_legacy_and_new_contracts_cannot_be_mixed(): void
    {
        [$employer, $job, $skill] = $this->scenario();

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/skills", [
            'skill_ids' => [$skill->id],
            'required_skills' => [['skill_id' => $skill->id, 'weight' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['skills']);
    }

    public function test_nice_to_have_only_job_cannot_be_published(): void
    {
        [$employer, $job, , $nice] = $this->scenario();
        $job->skills()->attach($nice->id, ['requirement_type' => 'nice_to_have', 'weight' => 1]);

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/publish")
            ->assertUnprocessable()->assertJsonPath('code', 'JOB_REQUIRED_SKILL_MISSING');
    }

    public function test_publish_rejects_invalid_legacy_pivot_type_and_weight(): void
    {
        [$employer, $job, $required, $nice] = $this->scenario();
        DB::table('job_posting_skills')->insert([
            'job_posting_id' => $job->id,
            'skill_id' => $required->id,
            'requirement_type' => 'invalid',
            'weight' => 1,
        ]);

        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/publish")
            ->assertUnprocessable()->assertJsonPath('code', 'JOB_SKILL_TYPE_INVALID');

        DB::table('job_posting_skills')->where('job_posting_id', $job->id)->delete();
        DB::table('job_posting_skills')->insert([
            'job_posting_id' => $job->id,
            'skill_id' => $nice->id,
            'requirement_type' => 'required',
            'weight' => 0,
        ]);
        $this->withToken($this->tokenFor($employer))->postJson("/api/v1/jobs/{$job->id}/publish")
            ->assertUnprocessable()->assertJsonPath('code', 'JOB_SKILL_WEIGHT_INVALID');
    }

    private function scenario(): array
    {
        $company = Company::create(['name' => 'Weighted Skills Co.', 'approval_status' => 'approved']);
        $employer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $employer->id, 'company_id' => $company->id]);
        $job = JobPosting::factory()->create([
            'company_id' => $company->id,
            'requirements' => 'API experience.',
            'status' => 'draft',
        ]);
        $required = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $nice = Skill::create(['name' => 'Docker', 'slug' => 'docker']);

        return [$employer, $job, $required, $nice];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(10))->plainTextToken;
    }
}
