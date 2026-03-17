<?php

use App\Jobs\ProcessAttribution;
use App\Jobs\Summarization\RunSummarization;
use App\Models\AttributionConnector;
use App\Models\AttributionKey;
use App\Models\AttributionResult;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\CampaignEmailRawData;
use App\Models\ConversionSale;
use App\Models\ConversionSaleRawData;
use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Workspace;
use App\Services\AttributionEngine;
use App\Services\ConnectorKeyProcessor;
use App\Services\EffortResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    // PIE hierarchy — default initiative for EffortResolver
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Program',
        'code' => 'TP',
    ]);
    $this->initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Test Initiative',
        'code' => 'TI',
        'is_default' => true,
    ]);
    $this->effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $this->initiative->id,
        'name' => 'Test Effort',
        'code' => 'TE',
        'channel_type' => 'email',
        'status' => 'active',
    ]);

    // Campaign integration (Maropost)
    $this->campaignIntegration = $this->workspace->integrations()->create([
        'name' => 'Test Maropost',
        'platform' => 'maropost',
        'data_types' => ['campaign_emails', 'campaign_email_clicks'],
        'is_active' => true,
    ]);
    $this->campaignIntegration->setCredentials(['account_id' => 'test', 'auth_token' => 'test']);

    // Conversion integration (Voluum)
    $this->conversionIntegration = $this->workspace->integrations()->create([
        'name' => 'Test Voluum',
        'platform' => 'voluum',
        'data_types' => ['conversion_sales'],
        'is_active' => true,
    ]);
    $this->conversionIntegration->setCredentials(['access_key_id' => 'test', 'access_key_secret' => 'test']);
});

/**
 * Helper to set up test data with campaign, click, conversion, and raw_data linkages.
 * No longer sets effort_id on campaign — EffortResolver handles that on attribution_keys.
 */
function seedAttributionData(
    $workspace,
    AttributionConnector $connector,
    $campaignIntegration,
    $conversionIntegration,
    string $email = 'test@example.com'
): void {
    $campRaw = CampaignEmailRawData::create([
        'workspace_id' => $workspace->id,
        'integration_id' => $campaignIntegration->id,
        'external_id' => 'camp-' . $email,
        'raw_data' => ['from_email' => $email, 'name' => 'Test Campaign'],
    ]);

    $campaign = CampaignEmail::create([
        'workspace_id' => $workspace->id,
        'raw_data_id' => $campRaw->id,
        'integration_id' => $campaignIntegration->id,
        'external_id' => 'camp-' . $email,
        'from_email' => $email,
    ]);

    CampaignEmailClick::create([
        'workspace_id' => $workspace->id,
        'campaign_email_id' => $campaign->id,
        'clicked_at' => now()->subDays(3),
    ]);

    $convRaw = ConversionSaleRawData::create([
        'workspace_id' => $workspace->id,
        'integration_id' => $conversionIntegration->id,
        'external_id' => 'conv-' . $email,
        'raw_data' => [
            'customVariable1' => $email,
            'customVariable1-TS' => 'campaignid',
        ],
    ]);

    ConversionSale::create([
        'workspace_id' => $workspace->id,
        'raw_data_id' => $convRaw->id,
        'integration_id' => $conversionIntegration->id,
        'external_id' => 'conv-' . $email,
        'revenue' => 100,
        'converted_at' => now(),
    ]);
}

it('end-to-end: processes all active connectors and all models', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Email Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    seedAttributionData($this->workspace, $connector, $this->campaignIntegration, $this->conversionIntegration);

    Bus::fake([RunSummarization::class]);

    $job = new ProcessAttribution($this->workspace);
    $job->handle(app(ConnectorKeyProcessor::class), app(EffortResolver::class), app(AttributionEngine::class));

    // Should have results for all 3 models
    expect(AttributionResult::where('model', 'first_touch')->count())->toBe(1);
    expect(AttributionResult::where('model', 'last_touch')->count())->toBe(1);
    expect(AttributionResult::where('model', 'linear')->count())->toBe(1);

    // EffortResolver auto-created an effort on the attribution key
    $key = AttributionKey::where('connector_id', $connector->id)->first();
    expect($key->effort_id)->not->toBeNull();

    // All results should reference the auto-created effort
    $autoEffort = Effort::find($key->effort_id);
    expect($autoEffort)->not->toBeNull();
    expect($autoEffort->auto_generated)->toBeTrue();
    expect(AttributionResult::where('effort_id', $autoEffort->id)->count())->toBe(3);

    // All results should have campaign_type and campaign_id populated
    AttributionResult::all()->each(function ($result) {
        expect($result->campaign_type)->not->toBeNull();
        expect($result->campaign_id)->not->toBeNull();
    });

    Bus::assertDispatched(RunSummarization::class);
});

it('skips inactive connectors', function () {
    $activeConnector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Active Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    // Use campaign_email_clicks data type to avoid unique constraint
    AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Inactive Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_email_clicks',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => false,
    ]);

    seedAttributionData($this->workspace, $activeConnector, $this->campaignIntegration, $this->conversionIntegration);

    Bus::fake([RunSummarization::class]);

    $job = new ProcessAttribution($this->workspace);
    $job->handle(app(ConnectorKeyProcessor::class), app(EffortResolver::class), app(AttributionEngine::class));

    // Only results from active connector
    expect(AttributionResult::where('connector_id', $activeConnector->id)->count())->toBe(3);
    expect(AttributionResult::count())->toBe(3);
});

it('processes only the specified connector when provided', function () {
    $connector1 = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Connector 1',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    // Use campaign_email_clicks data type to avoid unique constraint
    $connector2 = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Connector 2',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_email_clicks',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    seedAttributionData($this->workspace, $connector1, $this->campaignIntegration, $this->conversionIntegration);

    Bus::fake([RunSummarization::class]);

    // Only process connector1
    $job = new ProcessAttribution($this->workspace, $connector1);
    $job->handle(app(ConnectorKeyProcessor::class), app(EffortResolver::class), app(AttributionEngine::class));

    expect(AttributionResult::where('connector_id', $connector1->id)->count())->toBe(3);
    expect(AttributionResult::where('connector_id', $connector2->id)->count())->toBe(0);
});

it('runs only the specified model when provided', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    seedAttributionData($this->workspace, $connector, $this->campaignIntegration, $this->conversionIntegration);

    Bus::fake([RunSummarization::class]);

    $job = new ProcessAttribution($this->workspace, $connector, 'first_touch');
    $job->handle(app(ConnectorKeyProcessor::class), app(EffortResolver::class), app(AttributionEngine::class));

    expect(AttributionResult::where('model', 'first_touch')->count())->toBe(1);
    expect(AttributionResult::where('model', 'last_touch')->count())->toBe(0);
    expect(AttributionResult::where('model', 'linear')->count())->toBe(0);
});

it('partial failure: continues processing remaining connectors when one fails', function () {
    $connector1 = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Good Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    seedAttributionData($this->workspace, $connector1, $this->campaignIntegration, $this->conversionIntegration);

    // Mock ConnectorKeyProcessor to fail on second connector
    $mockProcessor = Mockery::mock(ConnectorKeyProcessor::class);
    $callCount = 0;
    $mockProcessor->shouldReceive('processKeys')
        ->andReturnUsing(function ($connector) use (&$callCount, $connector1) {
            $callCount++;
            if ($connector->id !== $connector1->id) {
                throw new RuntimeException('Simulated failure');
            }
            // For the good connector, use the real processor
            app(ConnectorKeyProcessor::class)->processKeys($connector);
        });

    // Use campaign_email_clicks data type to avoid unique constraint
    $connector2 = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Bad Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_email_clicks',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->atLeast()->once();

    Bus::fake([RunSummarization::class]);

    $job = new ProcessAttribution($this->workspace);
    $job->handle($mockProcessor, app(EffortResolver::class), app(AttributionEngine::class));

    // Good connector should have results
    expect(AttributionResult::where('connector_id', $connector1->id)->count())->toBeGreaterThan(0);
});

it('throws when ALL connectors fail', function () {
    AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Failing Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    $mockProcessor = Mockery::mock(ConnectorKeyProcessor::class);
    $mockProcessor->shouldReceive('processKeys')
        ->andThrow(new RuntimeException('All fail'));

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->atLeast()->once();

    $job = new ProcessAttribution($this->workspace);

    expect(fn () => $job->handle($mockProcessor, app(EffortResolver::class), app(AttributionEngine::class)))
        ->toThrow(RuntimeException::class, 'All connectors failed');
});

it('is idempotent: running twice produces same result count', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    seedAttributionData($this->workspace, $connector, $this->campaignIntegration, $this->conversionIntegration);

    $processor = app(ConnectorKeyProcessor::class);
    $resolver = app(EffortResolver::class);
    $engine = app(AttributionEngine::class);

    Bus::fake([RunSummarization::class]);

    $job1 = new ProcessAttribution($this->workspace);
    $job1->handle($processor, $resolver, $engine);
    $countAfterFirst = AttributionResult::count();

    $job2 = new ProcessAttribution($this->workspace);
    $job2->handle($processor, $resolver, $engine);
    $countAfterSecond = AttributionResult::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('dispatches to attribution queue', function () {
    $job = new ProcessAttribution($this->workspace);

    expect($job->queue)->toBe('attribution');
});

it('has correct uniqueId based on workspace', function () {
    $job = new ProcessAttribution($this->workspace);

    expect($job->uniqueId())->toBe((string) $this->workspace->id);
});

it('logs failure via failed method', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return $message === 'ProcessAttribution: Job failed'
                && $context['workspace_id'] === $this->workspace->id;
        });

    $job = new ProcessAttribution($this->workspace);
    $job->failed(new RuntimeException('Test failure'));
});

it('dispatches RunSummarization with correct workspace ID', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'campaignid']],
        'is_active' => true,
    ]);

    seedAttributionData($this->workspace, $connector, $this->campaignIntegration, $this->conversionIntegration);

    Bus::fake([RunSummarization::class]);

    $job = new ProcessAttribution($this->workspace);
    $job->handle(app(ConnectorKeyProcessor::class), app(EffortResolver::class), app(AttributionEngine::class));

    Bus::assertDispatched(RunSummarization::class, function ($job) {
        return $job->workspaceId === $this->workspace->id;
    });
});
