<?php

use App\Jobs\Extraction\UpsertRawData;
use App\Jobs\TransformExtractionBatches;
use App\Models\CampaignEmailClickRawData;
use App\Models\CampaignEmailRawData;
use App\Models\ConversionSaleRawData;
use App\Models\ExtractionBatch;
use App\Models\ExtractionRecord;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([TransformExtractionBatches::class]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->integration = new Integration([
        'name' => 'Test Integration',
        'platform' => 'activecampaign',
        'is_active' => true,
        'data_types' => ['campaign_emails', 'campaign_email_clicks', 'conversion_sales'],
        'sync_interval_minutes' => 60,
    ]);
    $this->integration->workspace_id = $this->workspace->id;
    $this->integration->organization_id = $this->org->id;
    $this->integration->save();
    $this->integration->credentials = [
        'api_url' => 'https://test.api-us1.com',
        'api_key' => 'test-key',
    ];
    $this->integration->save();
});

it('merges campaign emails via SQL with content hash', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::insert([
        [
            'extraction_batch_id' => $batch->id,
            'external_id' => 'ext-001',
            'payload' => json_encode(['external_id' => 'ext-001', 'name' => 'Campaign 1', 'sent' => 100]),
            'created_at' => now(),
        ],
        [
            'extraction_batch_id' => $batch->id,
            'external_id' => 'ext-002',
            'payload' => json_encode(['external_id' => 'ext-002', 'name' => 'Campaign 2', 'sent' => 200]),
            'created_at' => now(),
        ],
    ]);

    $job = new UpsertRawData($batch);
    $job->handle();

    $rows = CampaignEmailRawData::where('integration_id', $this->integration->id)->get();
    expect($rows)->toHaveCount(2);

    $first = $rows->firstWhere('external_id', 'ext-001');
    expect($first)->not->toBeNull();
    expect($first->content_hash)->not->toBeNull();
    expect($first->content_hash)->toHaveLength(64); // SHA-256 hex
    expect($first->workspace_id)->toBe($this->workspace->id);

    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_EXTRACTED);
    expect($batch->records_count)->toBe(2);
});

it('merges campaign email clicks via SQL with content hash', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_email_clicks',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::insert([
        [
            'extraction_batch_id' => $batch->id,
            'external_id' => '',
            'payload' => json_encode([
                'external_campaign_id' => 'camp-001',
                'subscriber_email_hash' => 'abc123hash',
                'click_url' => 'https://example.com/offer',
                'url_params' => ['utm_source' => 'email'],
            ]),
            'created_at' => now(),
        ],
    ]);

    $job = new UpsertRawData($batch);
    $job->handle();

    $row = CampaignEmailClickRawData::where('integration_id', $this->integration->id)->first();
    expect($row)->not->toBeNull();
    expect($row->external_campaign_id)->toBe('camp-001');
    expect($row->subscriber_email_hash)->toBe('abc123hash');
    expect($row->clicked_url)->toBe('https://example.com/offer');
    expect($row->content_hash)->not->toBeNull();
    expect($row->content_hash)->toHaveLength(64);

    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_EXTRACTED);
});

it('merges conversion sales via SQL with content hash', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'conversion_sales',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::insert([
        [
            'extraction_batch_id' => $batch->id,
            'external_id' => 'sale-001',
            'payload' => json_encode(['external_id' => 'sale-001', 'amount' => 99.99, 'currency' => 'USD']),
            'created_at' => now(),
        ],
    ]);

    $job = new UpsertRawData($batch);
    $job->handle();

    $row = ConversionSaleRawData::where('integration_id', $this->integration->id)->first();
    expect($row)->not->toBeNull();
    expect($row->external_id)->toBe('sale-001');
    expect($row->content_hash)->not->toBeNull();
    expect($row->content_hash)->toHaveLength(64);

    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_EXTRACTED);
});

it('updates existing rows on duplicate key without creating duplicates', function () {
    $batch1 = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::insert([
        [
            'extraction_batch_id' => $batch1->id,
            'external_id' => 'ext-001',
            'payload' => json_encode(['external_id' => 'ext-001', 'name' => 'Original', 'sent' => 100]),
            'created_at' => now(),
        ],
    ]);

    (new UpsertRawData($batch1))->handle();

    $firstRow = CampaignEmailRawData::where('integration_id', $this->integration->id)->first();
    $originalHash = $firstRow->content_hash;
    expect($originalHash)->not->toBeNull();

    // Second batch with updated data for same external_id
    $batch2 = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::insert([
        [
            'extraction_batch_id' => $batch2->id,
            'external_id' => 'ext-001',
            'payload' => json_encode(['external_id' => 'ext-001', 'name' => 'Updated', 'sent' => 200]),
            'created_at' => now(),
        ],
    ]);

    (new UpsertRawData($batch2))->handle();

    // Should still be 1 row, not 2
    $rows = CampaignEmailRawData::where('integration_id', $this->integration->id)->get();
    expect($rows)->toHaveCount(1);

    // Content hash should have changed
    $row = $rows->first();
    expect($row->content_hash)->not->toBe($originalHash);
});

it('falls back to payload external_id when extraction record external_id is empty', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::insert([
        [
            'extraction_batch_id' => $batch->id,
            'external_id' => '',
            'payload' => json_encode(['external_id' => 'from-payload', 'name' => 'Test']),
            'created_at' => now(),
        ],
    ]);

    (new UpsertRawData($batch))->handle();

    $row = CampaignEmailRawData::where('integration_id', $this->integration->id)->first();
    expect($row)->not->toBeNull();
    expect($row->external_id)->toBe('from-payload');
});

it('marks batch completed with zero records', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    (new UpsertRawData($batch))->handle();

    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_COMPLETED);
});

it('marks integration data type as loaded after successful merge', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::insert([
        [
            'extraction_batch_id' => $batch->id,
            'external_id' => 'ext-001',
            'payload' => json_encode(['external_id' => 'ext-001']),
            'created_at' => now(),
        ],
    ]);

    (new UpsertRawData($batch))->handle();

    $this->integration->refresh();
    expect($this->integration->sync_statuses['campaign_emails'])->toBe('loaded');
});
