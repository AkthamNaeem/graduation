<?php

namespace App\Console\Commands;

use App\Exceptions\ApplicationSnapshotException;
use App\Models\ApplicationSnapshot;
use App\Models\JobApplication;
use App\Services\ApplicationSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillApplicationSnapshots extends Command
{
    protected $signature = 'applications:backfill-snapshots
        {--dry-run : Inspect eligible applications without writing database records or files}
        {--application-id=* : Restrict the run to one or more application IDs}
        {--chunk=100 : Applications processed per database chunk}';

    protected $description = 'Create best-available immutable snapshots for legacy job applications';

    public function handle(ApplicationSnapshotService $snapshotService): int
    {
        $ids = collect($this->option('application-id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $chunk = max(1, min(1000, (int) $this->option('chunk')));
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['eligible' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0];

        $query = JobApplication::query()
            ->whereDoesntHave('snapshot')
            ->when($ids->isNotEmpty(), fn (Builder $query) => $query->whereIn('id', $ids->all()))
            ->with([
                'jobSeekerProfile.user',
                'jobSeekerProfile.city',
                'jobSeekerProfile.experiences',
                'jobSeekerProfile.education',
                'jobSeekerProfile.skills',
                'selectedCvFile',
                'screeningQuestionSnapshots.options',
                'screeningQuestionSnapshots.answer.selectedOptions.option',
            ]);

        $query->chunkById($chunk, function ($applications) use ($snapshotService, $dryRun, &$counts): void {
            foreach ($applications as $application) {
                $counts['eligible']++;

                if ($application->snapshot()->exists()) {
                    $counts['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    try {
                        $snapshotService->validateBackfillCandidate($application);
                    } catch (Throwable $exception) {
                        $counts['failed']++;
                        $this->warn("Application {$application->id}: {$exception->getMessage()}");
                    }

                    continue;
                }

                $snapshot = null;
                try {
                    DB::transaction(function () use ($snapshotService, $application, &$snapshot): void {
                        $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
                        if ($locked->snapshot()->exists()) {
                            throw new ApplicationSnapshotException(
                                __('domain_errors.APPLICATION_SNAPSHOT_ALREADY_EXISTS'),
                                'APPLICATION_SNAPSHOT_ALREADY_EXISTS',
                            );
                        }
                        $snapshot = $snapshotService->backfill($locked);
                    });
                    $counts['created']++;
                } catch (ApplicationSnapshotException $exception) {
                    if ($snapshot instanceof ApplicationSnapshot) {
                        $snapshotService->cleanupSnapshotFile($snapshot);
                    }
                    if ($exception->errorCode === 'APPLICATION_SNAPSHOT_ALREADY_EXISTS') {
                        $counts['skipped']++;

                        continue;
                    }
                    $counts['failed']++;
                    $this->warn("Application {$application->id}: {$exception->getMessage()}");
                } catch (Throwable $exception) {
                    if ($snapshot instanceof ApplicationSnapshot) {
                        $snapshotService->cleanupSnapshotFile($snapshot);
                    }
                    $counts['failed']++;
                    $this->warn("Application {$application->id}: {$exception->getMessage()}");
                }
            }
        });

        $mode = $dryRun ? 'Dry run' : 'Backfill';
        $this->info(sprintf(
            '%s complete: eligible=%d created=%d skipped=%d failed=%d',
            $mode,
            $counts['eligible'],
            $counts['created'],
            $counts['skipped'],
            $counts['failed'],
        ));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
