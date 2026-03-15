<?php

namespace App\Jobs;

use App\Exceptions\UnsupportedDataTypeException;
use App\Models\AttributionConnector;
use App\Models\ExtractionBatch;
use App\Services\Transformation\TransformerRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TransformBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900; // 15 minutes

    public function __construct(
        public ExtractionBatch $batch,
        public bool $force = false,
    ) {
        $this->onQueue(config('queues.transformation'));
    }

    public function uniqueId(): string
    {
        return (string) $this->batch->id;
    }

    /**
     * Calculate the backoff between retries (exponential).
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(TransformerRegistry $registry): void
    {
        // Skip if already transformed or not in extracted state
        if ($this->batch->status !== ExtractionBatch::STATUS_EXTRACTED) {
            Log::info("TransformBatch: Skipping batch [{$this->batch->id}] — status is '{$this->batch->status}'");

            return;
        }

        $this->batch->markTransforming();
        $this->batch->integration->markDataTypeStatus($this->batch->data_type, 'transforming');

        Log::info("TransformBatch: Starting transformation for batch [{$this->batch->id}]", [
            'data_type' => $this->batch->data_type,
            'integration_id' => $this->batch->integration_id,
            'workspace_id' => $this->batch->workspace_id,
        ]);

        $transformer = $registry->resolve($this->batch->data_type);

        // Apply force flag if set on the job or the batch
        if (($this->force || $this->batch->force_transform) && method_exists($transformer, 'setForce')) {
            $transformer->setForce(true);
        }

        $result = DB::transaction(function () use ($transformer) {
            return $transformer->transform($this->batch);
        });

        $this->batch->markTransformed();
        $this->batch->integration->markDataTypeStatus($this->batch->data_type, 'transformed');

        // Reset force_transform flag after successful transformation
        if ($this->batch->force_transform) {
            $this->batch->force_transform = false;
            $this->batch->save();
        }

        if ($result->total() === 0 && ! $result->hasErrors()) {
            Log::info("TransformBatch: No records to transform for batch [{$this->batch->id}]", [
                'data_type' => $this->batch->data_type,
                'integration_id' => $this->batch->integration_id,
            ]);
        } else {
            Log::info("TransformBatch: Completed batch [{$this->batch->id}]", [
                'created' => $result->created,
                'updated' => $result->updated,
                'skipped' => $result->skipped,
                'errors' => count($result->errors),
            ]);
        }

        // Check if all batches for this integration are transformed, then dispatch attribution
        $this->dispatchAttributionIfReady();
    }

    public function failed(Throwable $exception): void
    {
        if ($exception instanceof UnsupportedDataTypeException) {
            Log::info("TransformBatch: No transformer available for batch [{$this->batch->id}]", [
                'data_type' => $this->batch->data_type,
                'error' => $exception->getMessage(),
            ]);
        } else {
            Log::error("TransformBatch: Failed for batch [{$this->batch->id}]", [
                'exception' => get_class($exception),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        $this->batch->markTransformFailed($exception->getMessage());
        $this->batch->integration->markDataTypeStatus($this->batch->data_type, 'transform_failed');
    }

    /**
     * After successful transformation, check if all extraction batches
     * for this integration are transformed and dispatch attribution.
     */
    protected function dispatchAttributionIfReady(): void
    {
        $pendingBatches = ExtractionBatch::where('integration_id', $this->batch->integration_id)
            ->whereNotIn('status', [
                ExtractionBatch::STATUS_TRANSFORMED,
                ExtractionBatch::STATUS_COMPLETED,
                ExtractionBatch::STATUS_FAILED,
                ExtractionBatch::STATUS_TRANSFORM_FAILED,
            ])
            ->exists();

        if (! $pendingBatches) {
            $integration = $this->batch->integration;
            $workspace = $integration->workspace;

            if (! $workspace) {
                return;
            }

            $hasAttributionConnectors = AttributionConnector::where('workspace_id', $workspace->id)
                ->active()
                ->exists();

            if ($hasAttributionConnectors) {
                Log::info("TransformBatch: All batches transformed for integration [{$this->batch->integration_id}], dispatching attribution", [
                    'workspace_id' => $workspace->id,
                ]);

                $integration->markSyncPhase('attributing');
                ProcessAttribution::dispatch($workspace);
            } else {
                Log::info("TransformBatch: All batches transformed for integration [{$this->batch->integration_id}], no attribution connectors — marking sync completed", [
                    'workspace_id' => $workspace->id,
                ]);

                $integration->markSyncCompleted();
            }
        }
    }
}
