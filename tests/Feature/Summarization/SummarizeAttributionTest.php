<?php

use App\Jobs\Summarization\SummarizeAttribution;
use App\Models\AttributionConnector;
use App\Models\AttributionResult;
use App\Models\ConversionSale;
use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->integration = new Integration([
        'name' => 'Test',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
    ]);
    $this->integration->workspace_id = $this->workspace->id;
    $this->integration->organization_id = $this->org->id;
    $this->integration->save();

    $this->program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Program',
        'code' => 'TP1',
    ]);

    $this->initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $this->program->id,
        'name' => 'Test Initiative',
        'code' => 'TI1',
    ]);

    $this->effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $this->initiative->id,
        'name' => 'Test Effort',
        'code' => 'TE1',
        'channel_type' => 'email',
        'status' => 'active',
    ]);

    $this->connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => $this->integration->id,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => $this->integration->id,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
        'is_active' => true,
    ]);
});

it('aggregates attribution results into summary_attribution_daily', function () {
    $conversion1 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv1',
        'revenue' => 100.00,
        'converted_at' => '2026-03-01 10:00:00',
    ]);

    $conversion2 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv2',
        'revenue' => 200.00,
        'converted_at' => '2026-03-01 14:00:00',
    ]);

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $this->connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => $conversion1->id,
        'effort_id' => $this->effort->id,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $this->connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => $conversion2->id,
        'effort_id' => $this->effort->id,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    (new SummarizeAttribution($this->workspace->id))->handle();

    $summary = DB::table('summary_attribution_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-03-01')
        ->where('model', 'first_touch')
        ->first();

    expect($summary)->not->toBeNull();
    expect($summary->attributed_conversions)->toBe(2);
    expect((float) $summary->attributed_revenue)->toBe(300.00);
    expect((float) $summary->total_weight)->toBe(2.0);
});

it('aggregates by effort into summary_attribution_by_effort', function () {
    $effort2 = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $this->initiative->id,
        'name' => 'Effort 2',
        'code' => 'TE2',
        'channel_type' => 'email',
        'status' => 'active',
    ]);

    $conversion1 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv1',
        'revenue' => 100.00,
        'converted_at' => '2026-03-01',
    ]);

    $conversion2 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv2',
        'revenue' => 200.00,
        'converted_at' => '2026-03-01',
    ]);

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $this->connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => $conversion1->id,
        'effort_id' => $this->effort->id,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $this->connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => $conversion2->id,
        'effort_id' => $effort2->id,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    (new SummarizeAttribution($this->workspace->id))->handle();

    $effortSummaries = DB::table('summary_attribution_by_effort')
        ->where('workspace_id', $this->workspace->id)
        ->where('model', 'first_touch')
        ->get();

    expect($effortSummaries)->toHaveCount(2);

    $effort1Summary = $effortSummaries->firstWhere('effort_id', $this->effort->id);
    expect($effort1Summary->attributed_conversions)->toBe(1);
    expect((float) $effort1Summary->attributed_revenue)->toBe(100.00);

    $effort2Summary = $effortSummaries->firstWhere('effort_id', $effort2->id);
    expect($effort2Summary->attributed_conversions)->toBe(1);
    expect((float) $effort2Summary->attributed_revenue)->toBe(200.00);
});

it('handles multiple attribution models', function () {
    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv1',
        'revenue' => 100.00,
        'converted_at' => '2026-03-01',
    ]);

    foreach (['first_touch', 'last_touch', 'linear'] as $model) {
        AttributionResult::create([
            'workspace_id' => $this->workspace->id,
            'connector_id' => $this->connector->id,
            'conversion_type' => 'conversion_sale',
            'conversion_id' => $conversion->id,
            'effort_id' => $this->effort->id,
            'campaign_type' => 'campaign_email',
            'campaign_id' => 1,
            'model' => $model,
            'weight' => $model === 'linear' ? 0.5 : 1.0,
            'matched_at' => now(),
        ]);
    }

    (new SummarizeAttribution($this->workspace->id))->handle();

    $models = DB::table('summary_attribution_daily')
        ->where('workspace_id', $this->workspace->id)
        ->pluck('model')
        ->toArray();

    expect($models)->toContain('first_touch');
    expect($models)->toContain('last_touch');
    expect($models)->toContain('linear');

    $linearSummary = DB::table('summary_attribution_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('model', 'linear')
        ->first();

    expect((float) $linearSummary->attributed_revenue)->toBe(50.00); // 100 * 0.5 weight
});

it('aggregates by campaign into summary_attribution_by_campaign', function () {
    $conversion1 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv1',
        'revenue' => 150.00,
        'converted_at' => '2026-03-01',
    ]);

    $conversion2 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv2',
        'revenue' => 250.00,
        'converted_at' => '2026-03-01',
    ]);

    $conversion3 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'cv3',
        'revenue' => 400.00,
        'converted_at' => '2026-03-01',
    ]);

    // Two conversions attributed to campaign 10
    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $this->connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => $conversion1->id,
        'effort_id' => $this->effort->id,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 10,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $this->connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => $conversion2->id,
        'effort_id' => $this->effort->id,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 10,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    // One conversion attributed to campaign 20
    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $this->connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => $conversion3->id,
        'effort_id' => $this->effort->id,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 20,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    (new SummarizeAttribution($this->workspace->id))->handle();

    $campaignSummaries = DB::table('summary_attribution_by_campaign')
        ->where('workspace_id', $this->workspace->id)
        ->where('model', 'first_touch')
        ->get();

    expect($campaignSummaries)->toHaveCount(2);

    $campaign10Summary = $campaignSummaries->firstWhere('campaign_id', 10);
    expect($campaign10Summary)->not->toBeNull();
    expect($campaign10Summary->campaign_type)->toBe('campaign_email');
    expect($campaign10Summary->attributed_conversions)->toBe(2);
    expect((float) $campaign10Summary->attributed_revenue)->toBe(400.00);

    $campaign20Summary = $campaignSummaries->firstWhere('campaign_id', 20);
    expect($campaign20Summary)->not->toBeNull();
    expect($campaign20Summary->campaign_type)->toBe('campaign_email');
    expect($campaign20Summary->attributed_conversions)->toBe(1);
    expect((float) $campaign20Summary->attributed_revenue)->toBe(400.00);
});
