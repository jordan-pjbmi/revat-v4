<?php

use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use App\Models\SummaryAttributionByEffort;
use App\Models\SummaryAttributionDaily;
use App\Models\SummaryCampaignByPlatform;
use App\Models\SummaryCampaignDaily;
use App\Models\SummaryConversionDaily;
use App\Models\SummaryWorkspaceDaily;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();
});

// ── SummaryCampaignDaily ──────────────────────────────────────────────

it('SummaryCampaignDaily has no auto-increment and no timestamps', function () {
    $model = new SummaryCampaignDaily;
    expect($model->incrementing)->toBeFalse();
    expect($model->timestamps)->toBeFalse();
    expect($model->getKeyName())->toBe(['workspace_id', 'summary_date']);
});

it('SummaryCampaignDaily can upsert and read records', function () {
    DB::table('summary_campaign_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'campaigns_count' => 5,
        'sent' => 1000,
        'delivered' => 950,
        'bounced' => 50,
        'complaints' => 2,
        'unsubscribes' => 5,
        'opens' => 300,
        'unique_opens' => 250,
        'clicks' => 100,
        'unique_clicks' => 80,
        'platform_revenue' => 500.50,
        'summarized_at' => now(),
    ]);

    $record = SummaryCampaignDaily::forWorkspace($this->workspace->id)->first();
    expect($record)->not->toBeNull();
    expect($record->campaigns_count)->toBe(5);
    expect($record->summary_date)->toBeInstanceOf(Carbon::class);
    expect((float) $record->platform_revenue)->toBe(500.50);
});

it('SummaryCampaignDaily date range scope filters correctly', function () {
    foreach (['2026-03-01', '2026-03-15', '2026-04-01'] as $date) {
        DB::table('summary_campaign_daily')->insert([
            'workspace_id' => $this->workspace->id,
            'summary_date' => $date,
            'summarized_at' => now(),
        ]);
    }

    $results = SummaryCampaignDaily::forWorkspace($this->workspace->id)
        ->forDateRange(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'))
        ->get();

    expect($results)->toHaveCount(2);
});

// ── SummaryConversionDaily ────────────────────────────────────────────

it('SummaryConversionDaily has correct config', function () {
    $model = new SummaryConversionDaily;
    expect($model->incrementing)->toBeFalse();
    expect($model->timestamps)->toBeFalse();
    expect($model->getKeyName())->toBe(['workspace_id', 'summary_date']);
});

it('SummaryConversionDaily casts monetary fields', function () {
    DB::table('summary_conversion_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'conversions_count' => 10,
        'revenue' => 1000.50,
        'payout' => 500.25,
        'cost' => 100.00,
        'summarized_at' => now(),
    ]);

    $record = SummaryConversionDaily::forWorkspace($this->workspace->id)->first();
    expect((float) $record->revenue)->toBe(1000.50);
    expect((float) $record->payout)->toBe(500.25);
    expect((float) $record->cost)->toBe(100.00);
});

// ── SummaryCampaignByPlatform ─────────────────────────────────────────

it('SummaryCampaignByPlatform has platform scope', function () {
    $model = new SummaryCampaignByPlatform;
    expect($model->getKeyName())->toBe(['workspace_id', 'platform', 'summary_date']);

    DB::table('summary_campaign_by_platform')->insert([
        ['workspace_id' => $this->workspace->id, 'platform' => 'activecampaign', 'summary_date' => '2026-03-01', 'sent' => 100, 'summarized_at' => now()],
        ['workspace_id' => $this->workspace->id, 'platform' => 'expertsender', 'summary_date' => '2026-03-01', 'sent' => 200, 'summarized_at' => now()],
    ]);

    $acResults = SummaryCampaignByPlatform::forPlatform('activecampaign')->get();
    expect($acResults)->toHaveCount(1);
    expect($acResults->first()->sent)->toBe(100);
});

// ── SummaryAttributionDaily ───────────────────────────────────────────

it('SummaryAttributionDaily has model scope', function () {
    $model = new SummaryAttributionDaily;
    expect($model->getKeyName())->toBe(['workspace_id', 'summary_date', 'model']);

    DB::table('summary_attribution_daily')->insert([
        ['workspace_id' => $this->workspace->id, 'summary_date' => '2026-03-01', 'model' => 'first_touch', 'attributed_conversions' => 5, 'attributed_revenue' => 500.00, 'total_weight' => 5.0000, 'summarized_at' => now()],
        ['workspace_id' => $this->workspace->id, 'summary_date' => '2026-03-01', 'model' => 'last_touch', 'attributed_conversions' => 5, 'attributed_revenue' => 500.00, 'total_weight' => 5.0000, 'summarized_at' => now()],
    ]);

    $results = SummaryAttributionDaily::forModel('first_touch')->get();
    expect($results)->toHaveCount(1);
});

it('SummaryAttributionDaily casts weight to decimal:4', function () {
    DB::table('summary_attribution_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'model' => 'linear',
        'total_weight' => 3.3333,
        'summarized_at' => now(),
    ]);

    $record = SummaryAttributionDaily::first();
    expect($record->total_weight)->toBe('3.3333');
});

// ── SummaryAttributionByEffort ────────────────────────────────────────

it('SummaryAttributionByEffort has effort relationship and scope', function () {
    $model = new SummaryAttributionByEffort;
    expect($model->getKeyName())->toBe(['workspace_id', 'effort_id', 'summary_date', 'model']);

    $program = Program::factory()->create(['workspace_id' => $this->workspace->id]);
    $initiative = Initiative::factory()->create(['program_id' => $program->id, 'workspace_id' => $this->workspace->id]);
    $effort = Effort::factory()->create(['initiative_id' => $initiative->id, 'workspace_id' => $this->workspace->id]);

    DB::table('summary_attribution_by_effort')->insert([
        'workspace_id' => $this->workspace->id,
        'effort_id' => $effort->id,
        'summary_date' => '2026-03-01',
        'model' => 'first_touch',
        'attributed_conversions' => 3,
        'attributed_revenue' => 300.00,
        'total_weight' => 3.0000,
        'summarized_at' => now(),
    ]);

    $results = SummaryAttributionByEffort::forEffort($effort->id)->get();
    expect($results)->toHaveCount(1);
});

// ── SummaryWorkspaceDaily ─────────────────────────────────────────────

it('SummaryWorkspaceDaily has correct config and scopes', function () {
    $model = new SummaryWorkspaceDaily;
    expect($model->incrementing)->toBeFalse();
    expect($model->timestamps)->toBeFalse();
    expect($model->getKeyName())->toBe(['workspace_id', 'summary_date']);

    DB::table('summary_workspace_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'campaigns_count' => 10,
        'sent' => 5000,
        'opens' => 1500,
        'clicks' => 500,
        'conversions_count' => 50,
        'revenue' => 5000.00,
        'cost' => 1000.00,
        'summarized_at' => now(),
    ]);

    $record = SummaryWorkspaceDaily::forWorkspace($this->workspace->id)->first();
    expect($record->campaigns_count)->toBe(10);
    expect((float) $record->revenue)->toBe(5000.00);
    expect((float) $record->cost)->toBe(1000.00);
});

// ── Workspace Relationship ────────────────────────────────────────────

it('all summary models have workspace relationship', function () {
    expect(method_exists(SummaryCampaignDaily::class, 'workspace'))->toBeTrue();
    expect(method_exists(SummaryConversionDaily::class, 'workspace'))->toBeTrue();
    expect(method_exists(SummaryCampaignByPlatform::class, 'workspace'))->toBeTrue();
    expect(method_exists(SummaryAttributionDaily::class, 'workspace'))->toBeTrue();
    expect(method_exists(SummaryAttributionByEffort::class, 'workspace'))->toBeTrue();
    expect(method_exists(SummaryWorkspaceDaily::class, 'workspace'))->toBeTrue();
});
