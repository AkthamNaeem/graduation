<?php

namespace App\Console\Commands;

use App\Data\Recommendation\RecommendationPersistenceConfiguration;
use App\Models\RecommendationRun;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class PruneRecommendationRunsCommand extends Command
{
    protected $signature = 'recommendations:prune {--dry-run}';

    protected $description = 'Delete expired recommendation runs and their items';

    public function handle(
        RecommendationPersistenceConfiguration $configuration,
    ): int {
        $query = $this->prunableQuery($configuration);
        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->line("deleted_runs={$count}");
            Log::info('recommendation_runs_pruned', [
                'deleted_runs' => $count,
                'dry_run' => true,
            ]);

            return self::SUCCESS;
        }

        $deleted = 0;
        $query->select('id')->chunkById(
            500,
            function ($runs) use (&$deleted): void {
                $ids = $runs->pluck('id')->all();
                $deleted += RecommendationRun::query()->whereKey($ids)->delete();
            },
        );
        $this->line("deleted_runs={$deleted}");
        Log::info('recommendation_runs_pruned', [
            'deleted_runs' => $deleted,
            'dry_run' => false,
        ]);

        return self::SUCCESS;
    }

    private function prunableQuery(
        RecommendationPersistenceConfiguration $configuration,
    ): Builder {
        $now = now();
        $retentionCutoff = $now->copy()->subDays($configuration->retentionDays);

        return RecommendationRun::query()->where(function (Builder $query) use (
            $now,
            $retentionCutoff,
        ): void {
            $query->where('expires_at', '<=', $now)
                ->orWhere('generated_at', '<', $retentionCutoff);
        });
    }
}
