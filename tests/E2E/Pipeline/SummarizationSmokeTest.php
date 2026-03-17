<?php

use App\Jobs\Summarization\SummarizeAttribution;
use App\Jobs\Summarization\SummarizeCampaigns;
use App\Jobs\Summarization\SummarizeConversions;
use App\Models\AttributionConnector;
use App\Models\AttributionResult;
use App\Models\CampaignEmail;
use App\Models\ConversionSale;
use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Summarization Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->user = User::factory()->create([
        'current_organization_id' => $this->org->id,
    ]);
    $this->org->users()->attach($this->user);
    $this->workspace->users()->attach($this->user);
});

it('creates summary table records from fact data', function () {
    $integration = createIntegration([
        'workspace_id' => $this->workspace->id,
        'organization_id' => $this->org->id,
        'name' => 'Campaign Source',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
        'credentials' => ['api_key' => 'test-key'],
    ]);

    $sentDate = now()->subDays(5);

    // Seed campaign emails
    for ($i = 0; $i < 3; $i++) {
        CampaignEmail::create([
            'workspace_id' => $this->workspace->id,
            'integration_id' => $integration->id,
            'external_id' => "camp-{$i}",
            'name' => "Campaign {$i}",
            'sent' => 1000,
            'delivered' => 950,
            'bounced' => 20,
            'opens' => 300,
            'unique_opens' => 200,
            'clicks' => 50,
            'unique_clicks' => 30,
            'platform_revenue' => 100.00,
            'sent_at' => $sentDate,
        ]);
    }

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

    // Seed conversions
    for ($i = 0; $i < 5; $i++) {
        ConversionSale::create([
            'workspace_id' => $this->workspace->id,
            'integration_id' => $conversionIntegration->id,
            'external_id' => "conv-{$i}",
            'revenue' => 100.00,
            'payout' => 40.00,
            'cost' => 10.00,
            'converted_at' => $sentDate,
        ]);
    }

    // Seed attribution results
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Program',
        'code' => 'TP',
        'status' => 'active',
    ]);
    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Test Initiative',
        'code' => 'TI',
    ]);
    $effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Test Effort',
        'code' => 'TE1',
        'channel_type' => 'email',
        'status' => 'active',
    ]);
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => $integration->id,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => $conversionIntegration->id,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
        'is_active' => true,
    ]);

    $conversions = ConversionSale::where('workspace_id', $this->workspace->id)->get();
    foreach ($conversions as $conversion) {
        AttributionResult::create([
            'workspace_id' => $this->workspace->id,
            'connector_id' => $connector->id,
            'conversion_type' => 'conversion_sale',
            'conversion_id' => $conversion->id,
            'effort_id' => $effort->id,
            'model' => 'first_touch',
            'weight' => 1.0,
            'matched_at' => now(),
            'campaign_type' => 'campaign_email',
            'campaign_id' => 1,
        ]);
    }

    // Run summarization sub-jobs directly (RunSummarization dispatches async batches
    // which can't see the test transaction, so we call each job's handle() inline)
    (new SummarizeCampaigns($this->workspace->id))->handle();
    (new SummarizeConversions($this->workspace->id))->handle();
    (new SummarizeAttribution($this->workspace->id))->handle();

    // Verify campaign daily summary
    $campaignSummary = DB::table('summary_campaign_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', $sentDate->toDateString())
        ->first();

    expect($campaignSummary)->not->toBeNull();
    expect((int) $campaignSummary->campaigns_count)->toBe(3);
    expect((int) $campaignSummary->sent)->toBe(3000);
    expect((int) $campaignSummary->opens)->toBe(900);

    // Verify conversion daily summary
    $conversionSummary = DB::table('summary_conversion_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', $sentDate->toDateString())
        ->first();

    expect($conversionSummary)->not->toBeNull();
    expect((int) $conversionSummary->conversions_count)->toBe(5);
    expect((float) $conversionSummary->revenue)->toBe(500.00);

    // Verify attribution daily summary
    $attrSummary = DB::table('summary_attribution_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', $sentDate->toDateString())
        ->first();

    expect($attrSummary)->not->toBeNull();
    expect((int) $attrSummary->attributed_conversions)->toBe(5);
});
