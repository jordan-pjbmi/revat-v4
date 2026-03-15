<?php

namespace App\Jobs\Extraction;

use App\Jobs\TransformExtractionBatches;
use App\Models\ExtractionBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpsertRawData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 300;

    public function __construct(
        public ExtractionBatch $batch,
    ) {
        $this->queue = config('queues.extraction');
    }

    public function handle(): void
    {
        $batch = $this->batch->fresh();
        if (! $batch) {
            return;
        }

        $integration = $batch->integration;
        if (! $integration) {
            return;
        }

        $recordCount = $batch->records()->count();

        if ($recordCount === 0) {
            $batch->markCompleted();

            return;
        }

        try {
            match ($batch->data_type) {
                'campaign_emails' => $this->mergeCampaignEmails($batch),
                'campaign_email_clicks' => $this->mergeCampaignEmailClicks($batch),
                'conversion_sales' => $this->mergeConversionSales($batch),
                default => Log::warning("Unknown data type for upsert: {$batch->data_type}"),
            };

            $batch->records_count = $recordCount;
            $batch->save();
            $integration->markDataTypeStatus($batch->data_type, 'loaded');

            // Dispatch transformation — the job queries all extracted batches
            // with correct ordering (campaign_emails before clicks), so duplicate
            // dispatches from parallel upserts are safe.
            TransformExtractionBatches::dispatch();
        } catch (\Throwable $e) {
            $batch->markFailed("Upsert failed: {$e->getMessage()}");

            throw $e;
        }
    }

    protected function mergeCampaignEmails(ExtractionBatch $batch): void
    {
        DB::statement("
            INSERT INTO campaign_email_raw_data (workspace_id, integration_id, external_id, raw_data, content_hash, created_at)
            SELECT
                ?,
                ?,
                COALESCE(NULLIF(er.external_id, ''), JSON_UNQUOTE(JSON_EXTRACT(er.payload, '$.external_id')), ''),
                er.payload,
                SHA2(er.payload, 256),
                NOW()
            FROM extraction_records er
            WHERE er.extraction_batch_id = ?
            ON DUPLICATE KEY UPDATE
                raw_data = VALUES(raw_data),
                content_hash = VALUES(content_hash),
                updated_at = NOW()
        ", [$batch->workspace_id, $batch->integration_id, $batch->id]);
    }

    protected function mergeCampaignEmailClicks(ExtractionBatch $batch): void
    {
        DB::statement("
            INSERT INTO campaign_email_click_raw_data
                (workspace_id, integration_id, external_campaign_id, subscriber_email_hash, clicked_url, url_params, raw_data, content_hash, created_at)
            SELECT
                ?,
                ?,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(er.payload, '$.external_campaign_id')), ''),
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(er.payload, '$.subscriber_email_hash')), ''),
                JSON_UNQUOTE(JSON_EXTRACT(er.payload, '$.click_url')),
                COALESCE(JSON_EXTRACT(er.payload, '$.url_params'), '[]'),
                er.payload,
                SHA2(er.payload, 256),
                NOW()
            FROM extraction_records er
            WHERE er.extraction_batch_id = ?
            ON DUPLICATE KEY UPDATE
                clicked_url = VALUES(clicked_url),
                url_params = VALUES(url_params),
                raw_data = VALUES(raw_data),
                content_hash = VALUES(content_hash),
                updated_at = NOW()
        ", [$batch->workspace_id, $batch->integration_id, $batch->id]);
    }

    protected function mergeConversionSales(ExtractionBatch $batch): void
    {
        DB::statement("
            INSERT INTO conversion_sale_raw_data (workspace_id, integration_id, external_id, raw_data, content_hash, created_at)
            SELECT
                ?,
                ?,
                COALESCE(NULLIF(er.external_id, ''), JSON_UNQUOTE(JSON_EXTRACT(er.payload, '$.external_id')), ''),
                er.payload,
                SHA2(er.payload, 256),
                NOW()
            FROM extraction_records er
            WHERE er.extraction_batch_id = ?
            ON DUPLICATE KEY UPDATE
                raw_data = VALUES(raw_data),
                content_hash = VALUES(content_hash),
                updated_at = NOW()
        ", [$batch->workspace_id, $batch->integration_id, $batch->id]);
    }
}
