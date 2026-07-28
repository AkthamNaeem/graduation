<?php

namespace Tests\Feature;

use App\Contracts\Recommendation\RecommendationContextFingerprintContract;
use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Data\Recommendation\RecommendationEligibility;
use App\Models\ApplicationStatus;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobApplication;
use App\Models\Skill;
use App\Models\User;
use App\Services\CandidateExperienceCalculator;
use App\Services\Recommendation\RecommendationContextFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsRecommendationPersistenceScenarios;
use Tests\TestCase;

class RecommendationInvalidationTest extends TestCase
{
    use BuildsRecommendationPersistenceScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecommendationPersistenceScenario();
    }

    protected function tearDown(): void
    {
        $this->tearDownRecommendationPersistenceScenario();
        parent::tearDown();
    }

    public function test_recommendation_invalidation_fingerprint_is_canonical_and_deterministic(): void
    {
        $user = $this->recommendationUser();
        $company = $this->recommendationCompany();
        $skillA = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $skillB = Skill::create(['name' => 'SQL', 'slug' => 'sql']);
        $user->jobSeekerProfile->skills()->attach([$skillB->id, $skillA->id]);
        $firstJob = $this->recommendationJob($company);
        $secondJob = $this->recommendationJob($company);
        $firstJob->skills()->attach($skillB, [
            'requirement_type' => 'nice_to_have',
            'weight' => 2,
        ]);
        $firstJob->skills()->attach($skillA, [
            'requirement_type' => 'required',
            'weight' => 5,
        ]);
        $eligibility = $this->recommendationEligibility($user);
        $fingerprint = app(RecommendationContextFingerprintContract::class);

        $first = $fingerprint->fingerprint($eligibility, true);
        $repeat = $fingerprint->fingerprint($eligibility, true);
        $reordered = new RecommendationEligibility(
            $eligibility->profile,
            array_reverse($eligibility->jobs),
            $eligibility->now,
        );
        $reordered->profile->setRelation(
            'skills',
            $reordered->profile->skills->reverse()->values(),
        );

        $this->assertSame('recommendation-context-v1', $first->version);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first->hash);
        $this->assertSame(['hash', 'version'], array_keys(get_object_vars($first)));
        $this->assertStringNotContainsString(
            (string) $user->email,
            json_encode($first, JSON_THROW_ON_ERROR),
        );
        $this->assertSame($first->hash, $repeat->hash);
        $this->assertSame(
            $first->hash,
            $fingerprint->fingerprint($reordered, true)->hash,
        );

        $matching = (array) config('matching');
        $ml = (array) config('recommendation_ml');
        $reverseRecursive = function (array $value) use (&$reverseRecursive): array {
            $result = [];
            foreach (array_reverse($value, true) as $key => $item) {
                $result[$key] = is_array($item)
                    ? $reverseRecursive($item)
                    : $item;
            }

            return $result;
        };
        $canonical = new RecommendationContextFingerprint(
            app(CandidateExperienceCalculator::class),
            $reverseRecursive($matching),
            $reverseRecursive($ml),
            'recommendation-context-v1',
            'raw-score-published-at-job-id-v1',
        );
        $this->assertSame(
            $first->hash,
            $canonical->fingerprint($reordered, true)->hash,
        );
    }

    public function test_recommendation_invalidation_tracks_all_candidate_scoring_mutations(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $baseline = $this->currentHash($user);

        $user->jobSeekerProfile->update(['headline' => 'Principal Engineer']);
        $headline = $this->currentHash($user);
        $this->assertNotSame($baseline, $headline);

        $experience = Experience::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'title' => 'Backend Engineer',
            'company_name' => 'Example Company',
            'description' => 'Built APIs.',
            'start_date' => '2022-01-01',
            'end_date' => '2024-01-01',
            'is_current' => false,
        ]);
        $experienceCreated = $this->currentHash($user);
        $this->assertNotSame($headline, $experienceCreated);
        $experience->update(['description' => 'Built distributed APIs.']);
        $experienceUpdated = $this->currentHash($user);
        $this->assertNotSame($experienceCreated, $experienceUpdated);
        $experience->delete();
        $experienceDeleted = $this->currentHash($user);
        $this->assertNotSame($experienceUpdated, $experienceDeleted);

        $education = Education::create([
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'institution' => 'Example University',
            'degree' => 'Bachelor',
            'field_of_study' => 'Computer Science',
            'description' => 'Software engineering.',
        ]);
        $educationCreated = $this->currentHash($user);
        $this->assertNotSame($experienceDeleted, $educationCreated);
        $education->update(['degree' => 'Master']);
        $educationUpdated = $this->currentHash($user);
        $this->assertNotSame($educationCreated, $educationUpdated);
        $education->delete();
        $educationDeleted = $this->currentHash($user);
        $this->assertNotSame($educationUpdated, $educationDeleted);

        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $user->jobSeekerProfile->skills()->attach($skill);
        $skillAttached = $this->currentHash($user);
        $this->assertNotSame($educationDeleted, $skillAttached);
        $skill->update(['name' => 'Laravel Framework']);
        $skillUpdated = $this->currentHash($user);
        $this->assertNotSame($skillAttached, $skillUpdated);
        $user->jobSeekerProfile->skills()->detach($skill);
        $skillDetached = $this->currentHash($user);
        $this->assertNotSame($skillUpdated, $skillDetached);
    }

    public function test_recommendation_invalidation_tracks_jobs_skills_eligibility_and_config(): void
    {
        $user = $this->recommendationUser();
        $company = $this->recommendationCompany();
        $job = $this->recommendationJob($company);
        $required = Skill::create([
            'name' => 'Required Skill',
            'slug' => 'required-skill',
        ]);
        $nice = Skill::create(['name' => 'Nice Skill', 'slug' => 'nice-skill']);
        $job->skills()->attach($required, [
            'requirement_type' => 'required',
            'weight' => 5,
        ]);
        $job->skills()->attach($nice, [
            'requirement_type' => 'nice_to_have',
            'weight' => 2,
        ]);
        $baseline = $this->currentHash($user);

        $job->update(['description' => 'Changed scoring description.']);
        $description = $this->currentHash($user);
        $this->assertNotSame($baseline, $description);
        $job->skills()->updateExistingPivot($required->id, ['weight' => 4]);
        $requiredPivot = $this->currentHash($user);
        $this->assertNotSame($description, $requiredPivot);
        $job->skills()->updateExistingPivot($nice->id, ['weight' => 3]);
        $nicePivot = $this->currentHash($user);
        $this->assertNotSame($requiredPivot, $nicePivot);
        $job->update(['published_at' => now()->subHours(2)]);
        $published = $this->currentHash($user);
        $this->assertNotSame($nicePivot, $published);
        $job->update(['application_deadline' => now()->addDay()]);
        $deadline = $this->currentHash($user);
        $this->assertNotSame($published, $deadline);
        $job->update(['status' => 'closed']);
        $closed = $this->currentHash($user);
        $this->assertNotSame($deadline, $closed);
        $job->update(['status' => 'open']);
        $reopened = $this->currentHash($user);
        $this->assertNotSame($closed, $reopened);
        $company->update(['approval_status' => 'suspended']);
        $suspended = $this->currentHash($user);
        $this->assertNotSame($reopened, $suspended);
        $company->update(['approval_status' => 'approved']);

        $withoutApplication = $this->currentHash($user);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::firstOrFail()->id,
            'cover_letter' => null,
            'consent_to_share_profile' => true,
        ]);
        $withApplication = $this->currentHash($user);
        $this->assertNotSame($withoutApplication, $withApplication);
        $application->delete();
        $afterRemoval = $this->currentHash($user);
        $this->assertNotSame($withApplication, $afterRemoval);

        $eligibility = $this->recommendationEligibility($user);
        $fingerprint = app(RecommendationContextFingerprintContract::class);
        $enabled = $fingerprint->fingerprint($eligibility, true)->hash;
        $disabled = $fingerprint->fingerprint($eligibility, false)->hash;
        $this->assertNotSame($enabled, $disabled);
        config()->set('recommendation_ml.model_version', 'new-model-version');
        $changedService = new RecommendationContextFingerprint(
            app(CandidateExperienceCalculator::class),
            (array) config('matching'),
            (array) config('recommendation_ml'),
            'recommendation-context-v1',
            'raw-score-published-at-job-id-v1',
        );
        $this->assertNotSame(
            $enabled,
            $changedService->fingerprint($eligibility, true)->hash,
        );
    }

    public function test_recommendation_invalidation_ignores_user_and_profile_pii(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $baseline = $this->currentHash($user);

        $user->update([
            'name' => 'Changed Private Name',
            'email' => 'changed-private@example.test',
            'password' => 'changed-auth-state',
        ]);
        $user->jobSeekerProfile->update(['phone' => '+000000000']);

        $this->assertSame($baseline, $this->currentHash($user));
    }

    public function test_recommendation_invalidation_integration_misses_relevant_changes_and_reuses_unrelated_changes(): void
    {
        $user = $this->recommendationUser();
        $company = $this->recommendationCompany();
        $job = $this->recommendationJob($company);
        $this->fakeSuccessfulRecommendationMl();
        $orchestrator = app(RecommendationOrchestratorContract::class);

        $first = $orchestrator->recommend($user, 10);
        $user->update(['email' => 'unrelated-change@example.test']);
        $unrelated = $orchestrator->recommend($user, 10);
        $this->assertTrue($unrelated->cacheHit);
        $this->assertEquals($first->items, $unrelated->items);
        Http::assertSentCount(1);

        $user->jobSeekerProfile->update(['headline' => 'Changed Headline']);
        $profileMutation = $orchestrator->recommend($user, 10);
        $this->assertFalse($profileMutation->cacheHit);
        Http::assertSentCount(2);

        $job->update(['title' => 'Changed Job Scoring Title']);
        $jobMutation = $orchestrator->recommend($user, 10);
        $this->assertFalse($jobMutation->cacheHit);
        Http::assertSentCount(3);

        $job->update(['application_deadline' => now()->subSecond()]);
        $expired = $orchestrator->recommend($user, 10);
        $this->assertSame([], $expired->items);
        Http::assertSentCount(3);

        $job->update(['application_deadline' => null]);
        $application = JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::firstOrFail()->id,
            'cover_letter' => null,
            'consent_to_share_profile' => true,
        ]);
        $excluded = $orchestrator->recommend($user, 10);
        $this->assertSame([], $excluded->items);
        Http::assertSentCount(3);

        $application->delete();
        $restored = $orchestrator->recommend($user, 10);
        $this->assertCount(1, $restored->items);
        $this->assertTrue($restored->cacheHit || $restored->persistenceHit);

        $job->delete();
        $deleted = $orchestrator->recommend($user, 10);
        $this->assertSame([], $deleted->items);
        $this->assertDatabaseCount('recommendation_runs', 4);
    }

    private function currentHash(User $user): string
    {
        $eligibility = $this->recommendationEligibility($user);

        return $this->recommendationContext($eligibility)->hash;
    }
}
