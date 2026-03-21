<?php

use App\Jobs\ProcessAttribution;
use App\Jobs\Summarization\RunSummarization;
use App\Jobs\TransformBatch;
use App\Models\AttributionConnector;
use App\Models\AttributionResult;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\CampaignEmailRawData;
use App\Models\ConversionSale;
use App\Models\ConversionSaleRawData;
use App\Models\Effort;
use App\Models\ExtractionBatch;
use App\Models\ExtractionRecord;
use App\Models\IdentityHash;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AttributionEngine;
use App\Services\ConnectorKeyProcessor;
use App\Services\Dashboard\MetricsService;
use App\Services\EffortResolver;
use App\Services\Transformation\TransformerRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

it('flows data through the entire pipeline: extract -> transform -> attribute -> summarize -> dashboard', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // ── Stage 1: Foundation Setup ───────────────────────────────────────
    $org = Organization::create(['name' => 'Full Pipeline Org']);
    $workspace = new Workspace(['name' => 'Production']);
    $workspace->organization_id = $org->id;
    $workspace->is_default = true;
    $workspace->save();

    $user = User::factory()->create([
        'current_organization_id' => $org->id,
    ]);
    $org->users()->attach($user);
    $workspace->users()->attach($user);

    // PIE hierarchy
    $program = Program::create([
        'workspace_id' => $workspace->id,
        'name' => 'Email Program',
        'code' => 'EP',
        'status' => 'active',
    ]);
    $initiative = Initiative::create([
        'workspace_id' => $workspace->id,
        'program_id' => $program->id,
        'name' => 'Welcome Flow',
        'code' => 'WF',
        'is_default' => true,
    ]);
    Effort::create([
        'workspace_id' => $workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Welcome Email',
        'code' => 'WE1',
        'channel_type' => 'email',
        'status' => 'active',
    ]);

    // Integrations
    $campaignIntegration = createIntegration([
        'workspace_id' => $workspace->id,
        'organization_id' => $org->id,
        'name' => 'ActiveCampaign',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails', 'campaign_email_clicks'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
        'credentials' => ['api_key' => 'test'],
    ]);

    $conversionIntegration = createIntegration([
        'workspace_id' => $workspace->id,
        'organization_id' => $org->id,
        'name' => 'Voluum',
        'platform' => 'voluum',
        'data_types' => ['conversion_sales'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
        'credentials' => ['api_key' => 'test'],
    ]);

    // ── Stage 2: Extraction ─────────────────────────────────────────────
    $sentDate = now()->subDays(5);
    $sharedEmail = 'alice@example.com';

    // Seed raw data (simulating what extraction produces)
    $campaignRaw = CampaignEmailRawData::create([
        'workspace_id' => $workspace->id,
        'integration_id' => $campaignIntegration->id,
        'external_id' => 'camp-pipeline-1',
        'raw_data' => [
            'external_id' => 'camp-pipeline-1',
            'name' => 'Pipeline Welcome',
            'subject' => 'Welcome!',
            'fromname' => 'Pipeline Team',
            'fromemail' => $sharedEmail,
            'send_amt' => 2000,
            'delivered' => 1900,
            '_bounces' => 40,
            'unsubscribes' => 10,
            'opens' => 600,
            'uniqueopens' => 400,
            'linkclicks' => 100,
            'uniquelinkclicks' => 70,
            'sdate' => $sentDate->toIso8601String(),
        ],
    ]);

    $conversionRaw = ConversionSaleRawData::create([
        'workspace_id' => $workspace->id,
        'integration_id' => $conversionIntegration->id,
        'external_id' => $sharedEmail,
        'raw_data' => [
            'external_id' => $sharedEmail,
            'revenue' => 300.00,
            'payout' => 120.00,
            'cost' => 30.00,
            'converted_at' => now()->subDays(3)->toIso8601String(),
            'customVariable1' => $sharedEmail,
            'customVariable1-TS' => 'email',
        ],
    ]);

    // Create extraction batches
    $campaignBatch = ExtractionBatch::create([
        'integration_id' => $campaignIntegration->id,
        'workspace_id' => $workspace->id,
        'data_type' => 'campaign_emails',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    $conversionBatch = ExtractionBatch::create([
        'integration_id' => $conversionIntegration->id,
        'workspace_id' => $workspace->id,
        'data_type' => 'conversion_sales',
        'status' => ExtractionBatch::STATUS_EXTRACTED,
    ]);

    ExtractionRecord::create([
        'extraction_batch_id' => $campaignBatch->id,
        'external_id' => 'camp-pipeline-1',
        'payload' => $campaignRaw->raw_data,
    ]);

    ExtractionRecord::create([
        'extraction_batch_id' => $conversionBatch->id,
        'external_id' => 'conv-pipeline-1',
        'payload' => $conversionRaw->raw_data,
    ]);

    // Verify extraction data
    expect(ExtractionBatch::where('workspace_id', $workspace->id)->count())->toBe(2);
    expect(ExtractionRecord::count())->toBe(2);

    // ── Stage 3: Transformation ─────────────────────────────────────────
    $transformerRegistry = app(TransformerRegistry::class);
    (new TransformBatch($campaignBatch))->handle($transformerRegistry);
    (new TransformBatch($conversionBatch))->handle($transformerRegistry);

    // Verify fact tables
    $campaign = CampaignEmail::where('workspace_id', $workspace->id)
        ->where('external_id', 'camp-pipeline-1')
        ->first();
    expect($campaign)->not->toBeNull();
    expect($campaign->name)->toBe('Pipeline Welcome');
    expect((int) $campaign->sent)->toBe(2000);

    $conversion = ConversionSale::where('workspace_id', $workspace->id)
        ->where('external_id', $sharedEmail)
        ->first();
    expect($conversion)->not->toBeNull();
    expect((float) $conversion->revenue)->toBe(300.00);

    // Verify batches are transformed
    expect($campaignBatch->fresh()->status)->toBe(ExtractionBatch::STATUS_TRANSFORMED);
    expect($conversionBatch->fresh()->status)->toBe(ExtractionBatch::STATUS_TRANSFORMED);

    // ── Stage 4: Attribution ────────────────────────────────────────────
    // Effort resolution is handled automatically by EffortResolver via attribution_keys

    // Create identity hash and click
    $hash = hash('sha256', $sharedEmail, true);
    $identityHash = IdentityHash::create([
        'workspace_id' => $workspace->id,
        'hash' => bin2hex($hash),
        'type' => 'email',
        'hash_algorithm' => 'sha256',
        'normalized_email_domain' => 'example.com',
    ]);

    $click = CampaignEmailClick::create([
        'workspace_id' => $workspace->id,
        'integration_id' => $campaignIntegration->id,
        'campaign_email_id' => $campaign->id,
        'identity_hash_id' => $identityHash->id,
        'clicked_at' => $sentDate->copy()->addHours(2),
    ]);

    // Set up connector and keys
    $connector = AttributionConnector::create([
        'workspace_id' => $workspace->id,
        'name' => 'Pipeline Connector',
        'campaign_integration_id' => $campaignIntegration->id,
        'campaign_data_type' => 'campaign_emails',
        'conversion_integration_id' => $conversionIntegration->id,
        'conversion_data_type' => 'conversion_sales',
        'field_mappings' => [['campaign' => 'fromemail', 'conversion' => 'email']],
        'is_active' => true,
    ]);

    // Run attribution (ConnectorKeyProcessor will auto-generate keys and record keys)
    $job = new ProcessAttribution($workspace);
    $job->handle(app(ConnectorKeyProcessor::class), app(EffortResolver::class), app(AttributionEngine::class));

    $results = AttributionResult::where('workspace_id', $workspace->id)->get();
    expect($results->count())->toBeGreaterThan(0);

    $models = $results->pluck('model')->unique()->sort()->values()->toArray();
    expect($models)->toContain('first_touch');
    expect($models)->toContain('last_touch');
    expect($models)->toContain('linear');

    // ── Stage 5: Summarization ──────────────────────────────────────────
    $summarizeJob = new RunSummarization($workspace->id);
    $summarizeJob->handle();

    // Verify summaries were created
    $campaignSummary = DB::table('summary_campaign_daily')
        ->where('workspace_id', $workspace->id)
        ->first();
    expect($campaignSummary)->not->toBeNull();

    $conversionSummary = DB::table('summary_conversion_daily')
        ->where('workspace_id', $workspace->id)
        ->first();
    expect($conversionSummary)->not->toBeNull();

    // ── Stage 6: Dashboard Metrics ──────────────────────────────────────
    $metrics = MetricsService::forWorkspace($workspace->id);
    $overview = $metrics->getOverview(now()->subDays(30), now());

    expect($overview['sent'])->toBeGreaterThan(0);
    expect($overview['conversions'])->toBeGreaterThan(0);
    expect($overview['conversion_revenue'])->toBeGreaterThan(0);
});
