<?php

use App\Jobs\TransformBatch;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailRawData;
use App\Models\ConversionSale;
use App\Models\ConversionSaleRawData;
use App\Models\ExtractionBatch;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Transformation\TransformerRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Transform Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->integration = createIntegration([
        'workspace_id' => $this->workspace->id,
        'organization_id' => $this->org->id,
        'name' => 'Test Integration',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails', 'conversion_sales'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
        'credentials' => ['api_key' => 'test-key'],
    ]);
});

it('transforms campaign email raw data into fact table records', function () {
    // Seed raw data
    $rawData = CampaignEmailRawData::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'camp-001',
        'raw_data' => [
            'external_id' => 'camp-001',
            'name' => 'Welcome Campaign',
            'subject' => 'Welcome to Acme',
            'fromname' => 'Acme Marketing',
            'fromemail' => 'hello@acme.test',
            'send_amt' => 1000,
            'delivered' => 950,
            '_bounces' => 20,
            'unsubscribes' => 5,
            'opens' => 400,
            'uniqueopens' => 300,
            'linkclicks' => 80,
            'uniquelinkclicks' => 60,
            'sdate' => now()->subDays(5)->toIso8601String(),
        ],
    ]);

    // Create extraction batch in extracted state
    $batch = ExtractionBatch::create([
        'integration_id' => $this->integration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    // Run transformation
    (new TransformBatch($batch))->handle(app(TransformerRegistry::class));

    // Verify fact table record was created
    $campaign = CampaignEmail::where('workspace_id', $this->workspace->id)
        ->where('external_id', 'camp-001')
        ->first();

    expect($campaign)->not->toBeNull();
    expect($campaign->name)->toBe('Welcome Campaign');
    expect((int) $campaign->sent)->toBe(1000);
    expect($campaign->from_email)->toBe('hello@acme.test');

    // Verify batch status transitioned
    expect($batch->fresh()->status)->toBe(ExtractionBatch::STATUS_TRANSFORMED);
});

it('transforms conversion sale raw data into fact table records', function () {
    $conversionIntegration = createIntegration([
        'workspace_id' => $this->workspace->id,
        'organization_id' => $this->org->id,
        'name' => 'Conversion Source',
        'platform' => 'voluum',
        'data_types' => ['conversion_sales'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
        'credentials' => ['api_key' => 'test-key'],
    ]);

    ConversionSaleRawData::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $conversionIntegration->id,
        'external_id' => 'conv-001',
        'raw_data' => [
            'external_id' => 'conv-001',
            'revenue' => 250.00,
            'payout' => 100.00,
            'cost' => 25.00,
            'converted_at' => now()->subDays(3)->toIso8601String(),
        ],
    ]);

    $batch = ExtractionBatch::create([
        'integration_id' => $conversionIntegration->id,
        'workspace_id' => $this->workspace->id,
        'data_type' => 'conversion_sales',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    (new TransformBatch($batch))->handle(app(TransformerRegistry::class));

    $conversion = ConversionSale::where('workspace_id', $this->workspace->id)
        ->where('external_id', 'conv-001')
        ->first();

    expect($conversion)->not->toBeNull();
    expect((float) $conversion->revenue)->toBe(250.00);
    expect($batch->fresh()->status)->toBe(ExtractionBatch::STATUS_TRANSFORMED);
});
