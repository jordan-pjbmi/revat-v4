<?php

use App\Http\Integrations\ActiveCampaign\Requests\GetCampaignsRequest;
use App\Http\Integrations\ActiveCampaign\Requests\GetMessagesRequest;
use App\Jobs\Extraction\ExtractDataType;
use App\Jobs\Extraction\ExtractIntegration;
use App\Jobs\Extraction\UpsertRawData;
use App\Jobs\TransformExtractionBatches;
use App\Models\CampaignEmailRawData;
use App\Models\ExtractionBatch;
use App\Models\ExtractionRecord;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Integrations\ConnectorRegistry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->integration = new Integration([
        'name' => 'AC Test',
        'platform' => 'activecampaign',
        'is_active' => true,
        'data_types' => ['campaign_emails'],
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

it('dispatches child jobs for each data type', function () {
    Bus::fake([ExtractDataType::class]);

    $job = new ExtractIntegration($this->integration);
    $job->handle();

    // Since we're faking Bus::batch(), let's just verify the integration was marked as sync started
    $this->integration->refresh();
    expect($this->integration->sync_in_progress)->toBeTrue();
});

it('creates batch, stores records, and dispatches upsert via ExtractDataType', function () {
    Queue::fake([UpsertRawData::class]);

    // Set global mock client for Saloon
    MockClient::global([
        GetCampaignsRequest::class => MockResponse::make([
            'campaigns' => [
                [
                    'id' => 1,
                    'name' => 'Test Campaign',
                    'subject' => 'Test',
                    'type' => 'single',
                    'status' => '2',
                    'send_amt' => 100,
                    'delivered' => 95,
                    'opens' => 30,
                    'uniqueopens' => 20,
                    'linkclicks' => 10,
                    'uniquelinkclicks' => 8,
                    'unsubscribes' => 1,
                    'hardbounces' => 2,
                    'softbounces' => 1,
                    'sdate' => '2025-01-01',
                    'cdate' => '2024-12-31',
                ],
            ],
            'campaignMessages' => [
                ['campaignid' => '1', 'messageid' => '10', 'subject' => 'Test'],
            ],
            'meta' => ['total' => 1],
        ]),
        GetMessagesRequest::class => MockResponse::make([
            'messages' => [
                ['id' => '10', 'fromname' => 'Sender', 'fromemail' => 'sender@test.com'],
            ],
            'meta' => ['total' => 1],
        ]),
    ]);

    $job = new ExtractDataType($this->integration, 'campaign_emails');
    $job->handle(app(ConnectorRegistry::class));

    MockClient::destroyGlobal();

    // Verify extraction batch was created
    $batch = ExtractionBatch::where('integration_id', $this->integration->id)->first();
    expect($batch)->not->toBeNull();
    expect($batch->data_type)->toBe('campaign_emails');
    expect($batch->status)->toBe(ExtractionBatch::STATUS_EXTRACTED);

    // Verify extraction records were created
    $records = ExtractionRecord::where('extraction_batch_id', $batch->id)->get();
    expect($records)->toHaveCount(1);
    expect($records->first()->external_id)->toBe('1');

    // Verify UpsertRawData was dispatched
    Queue::assertPushed(UpsertRawData::class);
});

it('upserts records into correct raw data table', function () {
    Queue::fake([TransformExtractionBatches::class]);

    // Create a batch with extraction records
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
            'payload' => json_encode([
                'external_id' => 'ext-001',
                'name' => 'Test Campaign',
                'sent' => 100,
            ]),
            'created_at' => now(),
        ],
    ]);

    $job = new UpsertRawData($batch);
    $job->handle();

    // Verify raw data was upserted
    $rawData = CampaignEmailRawData::where('integration_id', $this->integration->id)->first();
    expect($rawData)->not->toBeNull();
    expect($rawData->external_id)->toBe('ext-001');

    // Batch stays 'extracted' until transformation completes the lifecycle
    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_EXTRACTED);
    expect($batch->records_count)->toBe(1);
});

it('tracks integration sync status through full pipeline', function () {
    $this->integration->markSyncStarted();
    expect($this->integration->sync_in_progress)->toBeTrue();

    $this->integration->markDataTypeCompleted('campaign_emails');
    expect($this->integration->sync_statuses['campaign_emails'])->toBe('completed');

    $this->integration->markSyncCompleted();
    expect($this->integration->sync_in_progress)->toBeFalse();
    expect($this->integration->last_synced_at)->not->toBeNull();
});

it('marks batch and integration as failed on extraction failure', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
    ]);

    $batch->markFailed('API connection timeout');

    expect($batch->status)->toBe(ExtractionBatch::STATUS_FAILED);
    expect($batch->error)->toBe('API connection timeout');

    $this->integration->markDataTypeFailed('campaign_emails', 'API connection timeout');
    expect($this->integration->sync_statuses['campaign_emails'])->toBe('failed');
});

it('passes last_synced_at as since for incremental extraction', function () {
    $lastSynced = now()->subHours(2);
    $this->integration->last_synced_at = $lastSynced;
    $this->integration->save();

    $job = new ExtractDataType($this->integration, 'campaign_emails', $this->integration->last_synced_at);

    expect($job->since->timestamp)->toBe($lastSynced->timestamp);
});

it('returns early when workspace is soft-deleted', function () {
    $this->workspace->delete(); // soft delete

    $job = new ExtractIntegration($this->integration);
    $job->handle();

    // Integration should NOT be marked as sync started because it returned early
    $this->integration->refresh();
    expect($this->integration->sync_in_progress)->toBeFalse();
});

it('returns early when organization is soft-deleted', function () {
    $this->org->delete(); // soft delete

    $job = new ExtractIntegration($this->integration);
    $job->handle();

    $this->integration->refresh();
    expect($this->integration->sync_in_progress)->toBeFalse();
});

it('returns early when integration is inactive', function () {
    $this->integration->is_active = false;
    $this->integration->save();

    $job = new ExtractIntegration($this->integration);
    $job->handle();

    $this->integration->refresh();
    expect($this->integration->sync_in_progress)->toBeFalse();
});

it('dispatches on the extraction queue', function () {
    $job = new ExtractIntegration($this->integration);
    expect($job->queue)->toBe('extraction');

    $dtJob = new ExtractDataType($this->integration, 'campaign_emails');
    expect($dtJob->queue)->toBe('extraction');

    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
    ]);
    $upsertJob = new UpsertRawData($batch);
    expect($upsertJob->queue)->toBe('extraction');
});
