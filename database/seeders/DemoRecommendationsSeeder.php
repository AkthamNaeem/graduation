<?php

namespace Database\Seeders;

use App\Data\Recommendation\RecommendationEngine;
use App\Models\JobPosting;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoRecommendationsSeeder extends Seeder
{
    public function run(): void
    {
        $now = DemoSeederContext::now();
        $profile = User::query()
            ->where('email', 'seeker.backend@workey.test')
            ->firstOrFail()
            ->jobSeekerProfile;
        $jobs = JobPosting::query()
            ->whereIn('title', ['Senior Laravel Backend Engineer', 'Part-time API Support Engineer', 'Machine Learning Engineer'])
            ->get()
            ->keyBy('title');

        $this->runWithItems(
            $profile->id,
            RecommendationEngine::ML_XGBRANKER,
            $now->copy()->subMinutes(5),
            $now->copy()->addMinutes(10),
            false,
            null,
            [
                [$jobs['Senior Laravel Backend Engineer'], 94.25, 2.71],
                [$jobs['Part-time API Support Engineer'], 78.40, 1.42],
            ],
        );
        $this->runWithItems(
            $profile->id,
            RecommendationEngine::MATCHING_V2,
            $now->copy()->subDays(2),
            $now->copy()->subDay(),
            false,
            null,
            [
                [$jobs['Senior Laravel Backend Engineer'], 88.00, null],
                [$jobs['Machine Learning Engineer'], 43.50, null],
            ],
        );
        $this->runWithItems(
            $profile->id,
            RecommendationEngine::MATCHING_V2_FALLBACK,
            $now->copy()->subMinutes(2),
            $now->copy()->addMinutes(3),
            true,
            'ML_SERVICE_UNAVAILABLE',
            [
                [$jobs['Senior Laravel Backend Engineer'], 86.75, null],
                [$jobs['Part-time API Support Engineer'], 72.25, null],
            ],
        );
    }

    /** @param list<array{JobPosting,float,float|null}> $items */
    private function runWithItems(
        int $profileId,
        RecommendationEngine $engine,
        Carbon $generatedAt,
        Carbon $expiresAt,
        bool $fallback,
        ?string $fallbackCode,
        array $items,
    ): void {
        $isMl = $engine === RecommendationEngine::ML_XGBRANKER;
        $run = RecommendationRun::query()->create([
            'job_seeker_profile_id' => $profileId,
            'request_id' => match ($engine) {
                RecommendationEngine::ML_XGBRANKER => '10000000-0000-4000-8000-000000000001',
                RecommendationEngine::MATCHING_V2 => '10000000-0000-4000-8000-000000000002',
                RecommendationEngine::MATCHING_V2_FALLBACK => '10000000-0000-4000-8000-000000000003',
            },
            'context_hash' => hash('sha256', "demo:{$profileId}:{$engine->value}:{$generatedAt->timestamp}"),
            'context_version' => 'recommendation-context-v1',
            'requested_limit' => 10,
            'candidate_count' => 3,
            'returned_count' => count($items),
            'engine' => $engine,
            'fallback_used' => $fallback,
            'fallback_code' => $fallbackCode,
            'model_version' => $isMl ? 'xgbranker-demo-v1' : null,
            'feature_schema_version' => $isMl ? 'job-rec-features-v1' : null,
            'explanation_contract_version' => $isMl ? 'recommendation-explanation-contract-v1' : null,
            'generated_at' => $generatedAt,
            'expires_at' => $expiresAt,
        ]);

        foreach ($items as $index => [$job, $score, $rawScore]) {
            RecommendationItem::query()->create([
                'recommendation_run_id' => $run->id,
                'job_posting_id' => $job->id,
                'rank' => $index + 1,
                'score' => $score,
                'raw_score' => $rawScore,
                'matching_score_version' => $isMl ? 'ml_xgbranker:xgbranker-demo-v1' : 'matching-v2.0',
                'breakdown' => [
                    'required_skills' => ['score' => round($score * 0.45, 2), 'max_score' => 45],
                    'nice_to_have_skills' => ['score' => round($score * 0.10, 2), 'max_score' => 10],
                    'experience' => ['score' => round($score * 0.20, 2), 'max_score' => 20],
                    'education' => ['score' => round($score * 0.10, 2), 'max_score' => 10],
                    'text_similarity' => ['score' => round($score * 0.15, 2), 'max_score' => 15],
                ],
                'reasons' => [
                    ['code' => 'REQUIRED_SKILLS_MATCH', 'message' => 'Required skills overlap was evaluated.', 'value' => $score],
                    ['code' => $fallback ? 'MATCHING_FALLBACK_USED' : 'EXPERIENCE_MATCH', 'message' => $fallback ? 'Local matching was used safely.' : 'Experience alignment was evaluated.', 'value' => $score],
                ],
                'created_at' => $generatedAt,
            ]);
        }
    }
}
