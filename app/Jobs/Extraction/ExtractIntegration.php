<?php

namespace App\Jobs\Extraction;

use App\Models\Integration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ExtractIntegration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public Integration $integration,
    ) {
        $this->queue = config('queues.extraction');
    }

    public function uniqueId(): int
    {
        return $this->integration->id;
    }

    public function uniqueFor(): int
    {
        return 30 * 60; // 30 minutes
    }

    public function handle(): void
    {
        $integration = $this->integration->fresh();

        if (! $integration || ! $integration->is_active) {
            return;
        }

        // Validate ownership: workspace and organization must exist
        $workspace = $integration->workspace;
        if (! $workspace || $workspace->trashed()) {
            return;
        }

        $organization = $workspace->organization;
        if (! $organization || $organization->trashed()) {
            return;
        }

        $integration->markSyncStarted();
        $integration->markSyncPhase('extracting');

        $dataTypes = $integration->data_types ?? [];
        if (empty($dataTypes)) {
            $integration->markSyncCompleted();

            return;
        }

        $since = $integration->last_synced_at;

        $jobs = collect($dataTypes)->map(function (string $dataType) use ($integration, $since) {
            return new ExtractDataType($integration, $dataType, $since);
        })->all();

        Bus::batch($jobs)
            ->allowFailures()
            ->name("Extract {$integration->platform}:{$integration->name}")
            ->then(function () use ($integration) {
                // Extraction done — data awaits transformation
                $integration->fresh()?->markSyncPhase('transforming');
            })
            ->catch(function ($batch, $e) use ($integration) {
                Log::error("Extraction batch failed for integration {$integration->id}: {$e->getMessage()}");
            })
            ->finally(function ($batch) use ($integration) {
                $fresh = $integration->fresh();
                if (! $fresh) {
                    return;
                }

                if ($batch->failedJobs > 0 && $batch->failedJobs >= $batch->totalJobs) {
                    $fresh->markSyncFailed('All data type extractions failed.');
                }
            })
            ->onQueue(config('queues.extraction'))
            ->dispatch();
    }
}
