<?php

namespace App\Jobs\Extraction;

use App\Models\ExtractionBatch;
use App\Models\ExtractionRecord;
use App\Models\Integration;
use App\Services\Integrations\ConnectorRegistry;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractDataType implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 2;

    public function __construct(
        public Integration $integration,
        public string $dataType,
        public ?Carbon $since = null,
    ) {
        $this->queue = config('queues.extraction');
    }

    public function handle(ConnectorRegistry $registry): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $integration = $this->integration->fresh();

        if (! $integration || ! $integration->is_active) {
            return;
        }

        // Validate ownership
        $workspace = $integration->workspace;
        if (! $workspace || $workspace->trashed()) {
            return;
        }

        $organization = $workspace->organization;
        if (! $organization || $organization->trashed()) {
            return;
        }

        $batch = ExtractionBatch::create([
            'integration_id' => $integration->id,
            'workspace_id' => $integration->workspace_id,
            'data_type' => $this->dataType,
        ]);

        $batch->markExtracting();
        $integration->markDataTypeStatus($this->dataType, 'extracting');

        try {
            $connector = $registry->resolve($integration);

            $records = match ($this->dataType) {
                'campaign_emails' => $connector->fetchCampaignEmails($integration, $this->since),
                'campaign_email_clicks' => $connector->fetchCampaignEmailClicks($integration, $this->since),
                'conversion_sales' => $connector->fetchConversionSales($integration, $this->since),
                default => collect(),
            };

            // Batch insert extraction records in chunks
            $records->chunk(500)->each(function ($chunk) use ($batch) {
                $inserts = $chunk->map(function ($record) use ($batch) {
                    $externalId = $record['external_id']
                        ?? $this->deriveExternalId($record);

                    return [
                        'extraction_batch_id' => $batch->id,
                        'external_id' => $externalId,
                        'payload' => json_encode($record),
                        'created_at' => now(),
                    ];
                })->all();

                ExtractionRecord::insert($inserts);
            });

            $batch->markExtracted();
            $integration->markDataTypeStatus($this->dataType, 'extracted');

            // Dispatch upsert job
            UpsertRawData::dispatch($batch);
        } catch (\Throwable $e) {
            $batch->markFailed($e->getMessage());
            $integration->markDataTypeFailed($this->dataType, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Generate a deterministic external_id from record data when the platform
     * doesn't provide one (e.g. click records).
     */
    private function deriveExternalId(array $record): string
    {
        $payload = json_encode($record, JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ExtractDataType permanently failed for integration {$this->integration->id}, data type {$this->dataType}: {$exception->getMessage()}");
    }
}
