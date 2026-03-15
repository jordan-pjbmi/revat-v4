<?php

namespace App\Jobs;

use App\Models\AttributionConnector;
use App\Models\Integration;
use App\Models\Workspace;
use App\Services\AttributionEngine;
use App\Services\ConnectorKeyProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAttribution implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600; // 10 minutes

    public int $uniqueFor = 900; // 15 minutes

    public function __construct(
        public Workspace $workspace,
        public ?AttributionConnector $connector = null,
        public ?string $model = null,
    ) {
        $this->onQueue(config('queues.attribution'));
    }

    /**
     * Unique ID scoped by workspace to prevent concurrent runs.
     */
    public function uniqueId(): string
    {
        return (string) $this->workspace->id;
    }

    public function handle(ConnectorKeyProcessor $keyProcessor, AttributionEngine $engine): void
    {
        $connectors = $this->resolveConnectors();
        $models = $this->resolveModels();

        $failedConnectors = [];
        $totalResults = 0;

        foreach ($connectors as $connector) {
            try {
                Log::info("ProcessAttribution: Starting key processing for connector [{$connector->id}] '{$connector->name}'", [
                    'workspace_id' => $this->workspace->id,
                ]);

                $keyProcessor->processKeys($connector);

                Log::info("ProcessAttribution: Key processing completed for connector [{$connector->id}]", [
                    'workspace_id' => $this->workspace->id,
                ]);

                foreach ($models as $model) {
                    $count = $engine->run($this->workspace, $connector, $model);
                    $totalResults += $count;

                    Log::info('ProcessAttribution: Attribution run completed', [
                        'workspace_id' => $this->workspace->id,
                        'connector_id' => $connector->id,
                        'model' => $model,
                        'results_written' => $count,
                    ]);
                }
            } catch (Throwable $e) {
                $failedConnectors[] = $connector->id;

                Log::error("ProcessAttribution: Connector [{$connector->id}] failed", [
                    'workspace_id' => $this->workspace->id,
                    'connector_id' => $connector->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("ProcessAttribution: Completed for workspace [{$this->workspace->id}]", [
            'total_results' => $totalResults,
            'failed_connectors' => $failedConnectors,
        ]);

        // If ALL connectors failed, throw so the job is marked as failed
        if (count($failedConnectors) === count($connectors) && count($connectors) > 0) {
            throw new \RuntimeException(
                "ProcessAttribution: All connectors failed for workspace [{$this->workspace->id}]. "
                .'Failed connector IDs: '.implode(', ', $failedConnectors)
            );
        }

        // Mark all attributing integrations in this workspace as completed
        Integration::where('workspace_id', $this->workspace->id)
            ->where('sync_in_progress', true)
            ->where('last_sync_status', 'attributing')
            ->each(fn (Integration $i) => $i->markSyncCompleted());
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessAttribution: Job failed', [
            'workspace_id' => $this->workspace->id,
            'connector_id' => $this->connector?->id,
            'model' => $this->model,
            'error' => $exception->getMessage(),
        ]);

        // Mark all attributing integrations in this workspace as failed
        Integration::where('workspace_id', $this->workspace->id)
            ->where('sync_in_progress', true)
            ->where('last_sync_status', 'attributing')
            ->each(fn (Integration $i) => $i->markSyncFailed('Attribution processing failed.'));
    }

    /**
     * Resolve which connectors to process.
     */
    protected function resolveConnectors(): iterable
    {
        if ($this->connector) {
            return [$this->connector];
        }

        return AttributionConnector::where('workspace_id', $this->workspace->id)
            ->active()
            ->get();
    }

    /**
     * Resolve which models to run.
     */
    protected function resolveModels(): array
    {
        if ($this->model) {
            return [$this->model];
        }

        return AttributionEngine::VALID_MODELS;
    }
}
