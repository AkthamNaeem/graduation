<?php

namespace Tests\Unit;

use App\Exceptions\JobPostingOperationException;
use App\Models\Company;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MatchingScoreV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_all_required_skills_award_45_points_using_skill_ids(): void
    {
        [$job, $profile, $skills] = $this->scenario();
        $job->skills()->attach([
            $skills[0]->id => ['requirement_type' => 'required', 'weight' => 5],
            $skills[1]->id => ['requirement_type' => 'required', 'weight' => 2],
        ]);
        $profile->skills()->attach([$skills[0]->id, $skills[1]->id]);

        $result = $this->score($job, $profile);

        $this->assertSame(45.0, $result['breakdown']['required_skills']['score']);
        $this->assertSame(7, $result['breakdown']['required_skills']['matched_weight']);
        $this->assertSame([], $result['missing_required_skills']);
    }

    public function test_required_skill_partial_match_is_weighted(): void
    {
        [$job, $profile, $skills] = $this->scenario();
        $job->skills()->attach([
            $skills[0]->id => ['requirement_type' => 'required', 'weight' => 5],
            $skills[1]->id => ['requirement_type' => 'required', 'weight' => 1],
        ]);
        $profile->skills()->attach($skills[1]->id);

        $result = $this->score($job, $profile);

        $this->assertSame(7.5, $result['breakdown']['required_skills']['score']);
        $this->assertSame(16.67, $result['breakdown']['required_skills']['match_percentage']);
        $this->assertSame($skills[0]->id, $result['missing_required_skills'][0]['id']);
    }

    public function test_unweighted_half_required_match_awards_22_point_5(): void
    {
        [$job, $profile, $skills] = $this->scenario();
        $job->skills()->attach([
            $skills[0]->id => ['requirement_type' => 'required', 'weight' => 1],
            $skills[1]->id => ['requirement_type' => 'required', 'weight' => 1],
        ]);
        $profile->skills()->attach($skills[0]->id);

        $this->assertSame(22.5, $this->score($job, $profile)['breakdown']['required_skills']['score']);
    }

    public function test_no_required_skill_match_awards_zero_required_points(): void
    {
        [$job, $profile, $skills] = $this->scenario();
        $job->skills()->attach($skills[0]->id, ['requirement_type' => 'required', 'weight' => 5]);

        $component = $this->score($job, $profile)['breakdown']['required_skills'];

        $this->assertSame(0.0, $component['score']);
        $this->assertSame(0.0, $component['match_percentage']);
    }

    public function test_nice_to_have_partial_match_is_separate_from_missing_required(): void
    {
        [$job, $profile, $skills] = $this->scenario();
        $job->skills()->attach([
            $skills[0]->id => ['requirement_type' => 'required', 'weight' => 1],
            $skills[1]->id => ['requirement_type' => 'nice_to_have', 'weight' => 3],
            $skills[2]->id => ['requirement_type' => 'nice_to_have', 'weight' => 1],
        ]);
        $profile->skills()->attach($skills[1]->id);

        $result = $this->score($job, $profile);

        $this->assertSame(7.5, $result['breakdown']['nice_to_have_skills']['score']);
        $this->assertSame([$skills[0]->name], collect($result['missing_required_skills'])->pluck('name')->all());
        $this->assertSame([$skills[1]->name], collect($result['matched_nice_to_have_skills'])->pluck('name')->all());
    }

    public function test_no_nice_to_have_skills_awards_10_as_not_applicable(): void
    {
        [$job, $profile] = $this->scenario();

        $component = $this->score($job, $profile)['breakdown']['nice_to_have_skills'];

        $this->assertSame(10.0, $component['score']);
        $this->assertTrue($component['not_applicable']);
    }

    public function test_all_or_no_nice_to_have_matches_award_10_or_zero(): void
    {
        [$job, $profile, $skills] = $this->scenario();
        $job->skills()->attach($skills[2]->id, ['requirement_type' => 'nice_to_have', 'weight' => 4]);

        $this->assertSame(0.0, $this->score($job, $profile)['breakdown']['nice_to_have_skills']['score']);
        $profile->skills()->attach($skills[2]->id);
        $this->assertSame(10.0, $this->score($job, $profile)['breakdown']['nice_to_have_skills']['score']);
    }

    public function test_experience_score_is_proportional_and_capped(): void
    {
        [$job, $profile] = $this->scenario(['experience_level' => 'mid']);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Engineer',
            'company_name' => 'Example',
            'start_date' => now()->subMonths(18)->toDateString(),
            'end_date' => now()->toDateString(),
            'is_current' => false,
        ]);

        $component = $this->score($job, $profile)['breakdown']['experience'];

        $this->assertEqualsWithDelta(10, $component['score'], 0.1);
        $this->assertSame(3.0, $component['required_years']);
    }

    public function test_zero_required_experience_awards_20_and_zero_candidate_against_requirement_awards_zero(): void
    {
        [$entryJob, $profile] = $this->scenario(['experience_level' => 'entry']);
        $this->assertSame(20.0, $this->score($entryJob, $profile)['breakdown']['experience']['score']);

        $entryJob->update(['experience_level' => 'senior']);
        $this->assertSame(0.0, $this->score($entryJob, $profile)['breakdown']['experience']['score']);
    }

    public function test_education_one_level_below_awards_half_points(): void
    {
        [$job, $profile] = $this->scenario(['education_level' => 'master']);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Example University',
            'degree' => 'Bachelor of Science',
        ]);

        $component = $this->score($job, $profile)['breakdown']['education'];

        $this->assertSame(5.0, $component['score']);
        $this->assertSame('bachelor', $component['candidate_level']);
        $this->assertFalse($component['not_applicable']);
    }

    public function test_education_at_or_above_requirement_awards_full_points(): void
    {
        [$job, $profile] = $this->scenario(['education_level' => 'bachelor']);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Example University',
            'degree' => 'Master of Science',
        ]);

        $this->assertSame(10.0, $this->score($job, $profile)['breakdown']['education']['score']);
    }

    public function test_unknown_education_awards_zero_when_requirement_exists(): void
    {
        [$job, $profile] = $this->scenario(['education_level' => 'bachelor']);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Example Institute',
            'degree' => 'Professional Program',
        ]);

        $this->assertSame(0.0, $this->score($job, $profile)['breakdown']['education']['score']);
    }

    public function test_text_similarity_uses_15_points_and_total_is_sum_of_components(): void
    {
        [$job, $profile] = $this->scenario();

        $result = $this->score($job, $profile, 0.5);
        $components = $result['breakdown'];
        $sum = $components['required_skills']['score']
            + $components['nice_to_have_skills']['score']
            + $components['experience']['score']
            + $components['education']['score']
            + $components['text_similarity']['score'];

        $this->assertSame(7.5, $components['text_similarity']['score']);
        $this->assertSame($sum, $result['score']);
        $this->assertSame('2.0', $result['matching_score_version']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function test_text_similarity_boundaries_award_zero_and_15(): void
    {
        [$job, $profile] = $this->scenario();

        $this->assertSame(0.0, $this->score($job, $profile, 0)['breakdown']['text_similarity']['score']);
        $this->assertSame(15.0, $this->score($job, $profile, 1)['breakdown']['text_similarity']['score']);
    }

    public function test_perfect_match_is_exactly_100(): void
    {
        [$job, $profile, $skills] = $this->scenario(['experience_level' => 'entry']);
        $job->skills()->attach($skills[0]->id, ['requirement_type' => 'required', 'weight' => 5]);
        $profile->skills()->attach($skills[0]->id);

        $this->assertSame(100.0, $this->score($job, $profile, 1)['score']);
    }

    public function test_changing_weight_recalculates_the_score_on_demand(): void
    {
        [$job, $profile, $skills] = $this->scenario();
        $job->skills()->attach([
            $skills[0]->id => ['requirement_type' => 'required', 'weight' => 1],
            $skills[1]->id => ['requirement_type' => 'required', 'weight' => 1],
        ]);
        $profile->skills()->attach($skills[0]->id);
        $before = $this->score($job, $profile)['score'];

        $job->skills()->updateExistingPivot($skills[0]->id, ['weight' => 5]);
        $after = $this->score($job, $profile)['score'];

        $this->assertGreaterThan($before, $after);
    }

    public function test_unsupported_experience_level_returns_stable_domain_error(): void
    {
        [$job, $profile] = $this->scenario(['experience_level' => 'invented']);

        try {
            $this->score($job, $profile);
            $this->fail('Expected unsupported experience error.');
        } catch (JobPostingOperationException $exception) {
            $this->assertSame('MATCHING_EXPERIENCE_LEVEL_UNSUPPORTED', $exception->errorCode);
        }
    }

    public function test_matching_configuration_must_sum_to_100(): void
    {
        [$job, $profile] = $this->scenario();
        config()->set('matching.components.text_similarity', 14);

        try {
            $this->score($job, $profile);
            $this->fail('Expected configuration error.');
        } catch (JobPostingOperationException $exception) {
            $this->assertSame('MATCHING_CONFIGURATION_INVALID', $exception->errorCode);
        }
    }

    public function test_invalid_education_level_returns_stable_domain_error(): void
    {
        [$job, $profile] = $this->scenario(['education_level' => 'invented']);

        try {
            $this->score($job, $profile);
            $this->fail('Expected invalid education error.');
        } catch (JobPostingOperationException $exception) {
            $this->assertSame('MATCHING_EDUCATION_LEVEL_INVALID', $exception->errorCode);
        }
    }

    public function test_scoring_does_not_create_audit_notification_or_status_history(): void
    {
        [$job, $profile] = $this->scenario();
        $before = [
            'audit_logs' => $this->countRows('audit_logs'),
            'notifications' => $this->countRows('notifications'),
            'application_status_histories' => $this->countRows('application_status_histories'),
        ];

        $this->score($job, $profile);

        $this->assertSame($before['audit_logs'], $this->countRows('audit_logs'));
        $this->assertSame($before['notifications'], $this->countRows('notifications'));
        $this->assertSame($before['application_status_histories'], $this->countRows('application_status_histories'));
    }

    private function score(JobPosting $job, JobSeekerProfile $profile, float $similarity = 0): array
    {
        return (new MatchingService)->scoreMatch($job->fresh(), $profile->fresh(), $similarity);
    }

    private function scenario(array $jobOverrides = []): array
    {
        $company = Company::create(['name' => 'Score Test Co.', 'approval_status' => 'approved']);
        $job = JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Engineer',
            'description' => 'Build services.',
            'requirements' => 'Professional experience.',
            'employment_type' => 'full-time',
            'experience_level' => '',
            'work_mode' => 'remote',
            'status' => 'draft',
        ], $jobOverrides));
        $profile = JobSeekerProfile::create(['user_id' => User::factory()->create()->id]);
        $skills = collect(['Laravel', 'MySQL', 'Docker'])->map(fn (string $name): Skill => Skill::create([
            'name' => $name,
            'slug' => strtolower($name),
        ]))->all();

        return [$job, $profile, $skills];
    }

    private function countRows(string $table): int
    {
        return DB::table($table)->count();
    }
}
