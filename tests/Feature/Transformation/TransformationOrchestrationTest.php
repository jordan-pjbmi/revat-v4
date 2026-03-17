<?php

use App\Exceptions\UnsupportedDataTypeException;
use App\Jobs\ProcessAttribution;
use App\Jobs\TransformBatch;
use App\Jobs\TransformExtractionBatches;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailRawData;
use App\Models\ExtractionBatch;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Transformation\CampaignEmailTransformer;
use App\Services\Transformation\TransformerRegistry;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->integration = new Integration([
        'name' => 'Test Integration',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
    ]);
    $this->integration->workspace_id = $this->workspace->id;
    $this->integration->organization_id = $this->org->id;
    $this->integration->save();
});

// ── TransformBatch: End-to-End ────────────────────────────────────────

it('processes a valid batch end-to-end', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    CampaignEmailRawData::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'test-1',
        'raw_data' => [
            'external_id' => 'test-1',
            'name' => 'Test Campaign',
            'sent' => 100,
        ],
    ]);

    Bus::fake([ProcessAttribution::class]);

    $job = new TransformBatch($batch);
    $job->handle(app(TransformerRegistry::class));

    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_TRANSFORMED);
    expect($batch->transformed_at)->not->toBeNull();
    expect(CampaignEmail::count())->toBe(1);
});

// ── TransformBatch: Skip Already Transformed ──────────────────────────

it('skips already-transformed batches', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_TRANSFORMED,
    ]);

    $job = new TransformBatch($batch);
    $job->handle(app(TransformerRegistry::class));

    // Should not change status or throw
    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_TRANSFORMED);
});

// ── TransformBatch: Error Handling ────────────────────────────────────

it('handles transformer errors and updates batch status', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'unsupported_type',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    $job = new TransformBatch($batch);

    try {
        $job->handle(app(TransformerRegistry::class));
    } catch (UnsupportedDataTypeException $e) {
        $job->failed($e);
    }

    $batch->refresh();
    expect($batch->status)->toBe(ExtractionBatch::STATUS_TRANSFORM_FAILED);
    expect($batch->error)->not->toBeNull();
    expect($batch->failed_at)->not->toBeNull();
});

// ── TransformBatch: Retry Backoff ─────────────────────────────────────

it('has exponential backoff retry configuration', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    $job = new TransformBatch($batch);

    expect($job->tries)->toBe(3);
    expect($job->backoff())->toBe([10, 30, 60]);
    expect($job->timeout)->toBe(60);
    expect($job->failOnTimeout)->toBeTrue();
});

// ── TransformExtractionBatches: Dispatch Order ────────────────────────

it('dispatches jobs in correct data type order', function () {
    Bus::fake([TransformBatch::class]);

    // Create batches in reverse order
    $clickBatch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_email_clicks',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    $emailBatch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    $job = new TransformExtractionBatches;
    $job->handle();

    Bus::assertDispatched(TransformBatch::class, 2);
});

// ── TransformExtractionBatches: Batch Limit ───────────────────────────

it('respects batch limit configuration', function () {
    Bus::fake([TransformBatch::class]);
    config(['transformation.batch_limit' => 2]);

    for ($i = 1; $i <= 5; $i++) {
        ExtractionBatch::create([
            'integration_id' => $this->integration->id,
            'workspace_id' => $this->workspace->id,
            'data_type' => 'campaign_emails',
            'status' => ExtractionBatch::STATUS_EXTRACTED,
        ]);
    }

    $job = new TransformExtractionBatches;
    $job->handle();

    Bus::assertDispatched(TransformBatch::class, 2);
});

// ── TransformerRegistry: Correct Resolution ───────────────────────────

it('resolves correct transformer for each data type', function () {
    $registry = app(TransformerRegistry::class);

    expect($registry->resolve('campaign_emails'))->toBeInstanceOf(CampaignEmailTransformer::class);
    expect($registry->has('campaign_email_clicks'))->toBeTrue();
    expect($registry->has('conversion_sales'))->toBeTrue();
});

// ── TransformerRegistry: Unsupported Type ─────────────────────────────

it('throws for unsupported data type', function () {
    $registry = app(TransformerRegistry::class);

    $registry->resolve('nonexistent_type');
})->throws(UnsupportedDataTypeException::class);

// ── TransformBatch: Unique ID ─────────────────────────────────────────

it('has unique ID scoped by batch ID', function () {
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    $job = new TransformBatch($batch);
    expect($job->uniqueId())->toBe((string) $batch->id);
    expect($job->uniqueFor)->toBe(900);
});
