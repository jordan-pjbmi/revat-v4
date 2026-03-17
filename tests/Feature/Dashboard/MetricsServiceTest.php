<?php

use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Workspace;
use App\Services\Dashboard\MetricsService;
use Carbon\Carbon;
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

    // Seed summary tables directly
    DB::table('summary_campaign_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'campaigns_count' => 3,
        'sent' => 1000,
        'delivered' => 950,
        'bounced' => 20,
        'complaints' => 2,
        'unsubscribes' => 5,
        'opens' => 400,
        'unique_opens' => 350,
        'clicks' => 100,
        'unique_clicks' => 80,
        'platform_revenue' => 500.00,
        'summarized_at' => now(),
    ]);

    DB::table('summary_campaign_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-02',
        'campaigns_count' => 2,
        'sent' => 500,
        'delivered' => 480,
        'bounced' => 10,
        'complaints' => 1,
        'unsubscribes' => 3,
        'opens' => 200,
        'unique_opens' => 175,
        'clicks' => 50,
        'unique_clicks' => 40,
        'platform_revenue' => 250.00,
        'summarized_at' => now(),
    ]);

    DB::table('summary_conversion_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'conversions_count' => 10,
        'revenue' => 2000.00,
        'payout' => 500.00,
        'cost' => 300.00,
        'summarized_at' => now(),
    ]);

    DB::table('summary_conversion_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-02',
        'conversions_count' => 5,
        'revenue' => 1000.00,
        'payout' => 250.00,
        'cost' => 150.00,
        'summarized_at' => now(),
    ]);

    DB::table('summary_workspace_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'campaigns_count' => 3,
        'sent' => 1000,
        'opens' => 400,
        'clicks' => 100,
        'conversions_count' => 10,
        'revenue' => 2000.00,
        'cost' => 300.00,
        'summarized_at' => now(),
    ]);

    DB::table('summary_workspace_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-02',
        'campaigns_count' => 2,
        'sent' => 500,
        'opens' => 200,
        'clicks' => 50,
        'conversions_count' => 5,
        'revenue' => 1000.00,
        'cost' => 150.00,
        'summarized_at' => now(),
    ]);

    $this->service = MetricsService::forWorkspace($this->workspace->id);
    $this->start = Carbon::parse('2026-03-01');
    $this->end = Carbon::parse('2026-03-02');
});

it('getCampaignMetrics returns correct sums', function () {
    $metrics = $this->service->getCampaignMetrics($this->start, $this->end);

    expect($metrics['campaigns'])->toBe(5);
    expect($metrics['sent'])->toBe(1500);
    expect($metrics['opens'])->toBe(600);
    expect($metrics['unique_opens'])->toBe(525);
    expect($metrics['clicks'])->toBe(150);
    expect($metrics['unique_clicks'])->toBe(120);
    expect($metrics['open_rate'])->toBe(35.00); // 525/1500*100
    expect($metrics['click_rate'])->toBe(8.00); // 120/1500*100
    expect($metrics['revenue'])->toBe(750.00);
});

it('getConversionMetrics returns correct sums', function () {
    $metrics = $this->service->getConversionMetrics($this->start, $this->end);

    expect($metrics['conversions'])->toBe(15);
    expect($metrics['conversion_revenue'])->toBe(3000.00);
    expect($metrics['payout'])->toBe(750.00);
    expect($metrics['cost'])->toBe(450.00);
});

it('getOverview merges campaign and conversion metrics', function () {
    $overview = $this->service->getOverview($this->start, $this->end);

    expect($overview)->toHaveKey('campaigns');
    expect($overview)->toHaveKey('conversions');
    expect($overview)->toHaveKey('open_rate');
    expect($overview)->toHaveKey('conversion_revenue');
    expect($overview['sent'])->toBe(1500);
    expect($overview['conversions'])->toBe(15);
});

it('getPreviousPeriodComparison computes correct percent changes', function () {
    $result = $this->service->getPreviousPeriodComparison($this->start, $this->end);

    expect($result)->toHaveKey('current');
    expect($result)->toHaveKey('previous');
    expect($result)->toHaveKey('changes');

    expect($result['current']['sent'])->toBe(1500);
    // Previous period has no data, so all changes should be 100%
    expect($result['changes']['sent'])->toBe(100.00);
});

it('getDailyTrend fills missing dates with zeros', function () {
    $trend = $this->service->getDailyTrend(
        Carbon::parse('2026-02-28'),
        Carbon::parse('2026-03-03'),
    );

    expect($trend['dates'])->toHaveCount(4); // Feb 28, Mar 1, Mar 2, Mar 3
    expect($trend['dates'][0])->toBe('2026-02-28');
    expect($trend['sent'][0])->toBe(0); // Feb 28 - no data
    expect($trend['sent'][1])->toBe(1000); // Mar 1
    expect($trend['sent'][2])->toBe(500); // Mar 2
    expect($trend['sent'][3])->toBe(0); // Mar 3 - no data
    expect($trend['revenue'][1])->toBe(2000.00);
});

it('getGroupedReport day mode returns one row per date', function () {
    $report = $this->service->getGroupedReport($this->start, $this->end, 'day');

    expect($report['rows'])->toHaveCount(2);
    expect($report['rows'][0]['group_label'])->toBe('2026-03-01');
    expect($report['rows'][0]['sent'])->toBe(1000);
    expect($report['rows'][1]['group_label'])->toBe('2026-03-02');

    expect($report['totals']['sent'])->toBe(1500);
    expect($report['totals'])->toHaveKey('profit');
});

it('getGroupedReport week mode aggregates by ISO week', function () {
    $report = $this->service->getGroupedReport($this->start, $this->end, 'week');

    // Mar 1 (Sun) = ISO week 9, Mar 2 (Mon) = ISO week 10 in 2026
    expect($report['rows'])->toHaveCount(2);
    expect($report['totals']['sent'])->toBe(1500);
});

it('getGroupedReport month mode aggregates by year-month', function () {
    $report = $this->service->getGroupedReport($this->start, $this->end, 'month');

    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0]['group_label'])->toBe('2026-03');
    expect($report['totals']['sent'])->toBe(1500);
});

it('getGroupedReport platform mode reads from summary_campaign_by_platform', function () {
    DB::table('summary_campaign_by_platform')->insert([
        'workspace_id' => $this->workspace->id,
        'platform' => 'activecampaign',
        'summary_date' => '2026-03-01',
        'campaigns_count' => 2,
        'sent' => 800,
        'delivered' => 780,
        'opens' => 300,
        'unique_opens' => 250,
        'clicks' => 80,
        'unique_clicks' => 60,
        'platform_revenue' => 400.00,
        'summarized_at' => now(),
    ]);

    $report = $this->service->getGroupedReport($this->start, $this->end, 'platform');

    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0]['group_label'])->toBe('activecampaign');
    expect($report['rows'][0]['sent'])->toBe(800);
});

it('getAttributionSummary returns correct totals', function () {
    DB::table('summary_attribution_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'model' => 'first_touch',
        'attributed_conversions' => 8,
        'attributed_revenue' => 1500.00,
        'total_weight' => 8.0,
        'summarized_at' => now(),
    ]);

    $summary = $this->service->getAttributionSummary($this->start, $this->end, 'first_touch');

    expect($summary['attributed_conversions'])->toBe(8);
    expect($summary['attributed_revenue'])->toBe(1500.00);
    expect($summary['total_weight'])->toBe(8.0);
});

it('getAttributionByEffort returns effort-level breakdown', function () {
    $program = Program::create(['workspace_id' => $this->workspace->id, 'name' => 'P1', 'code' => 'P1']);
    $initiative = Initiative::create(['workspace_id' => $this->workspace->id, 'program_id' => $program->id, 'name' => 'I1', 'code' => 'I1']);
    $effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Newsletter Campaign',
        'code' => 'NL1',
        'channel_type' => 'email',
        'status' => 'active',
    ]);

    DB::table('summary_attribution_by_effort')->insert([
        'workspace_id' => $this->workspace->id,
        'effort_id' => $effort->id,
        'summary_date' => '2026-03-01',
        'model' => 'first_touch',
        'attributed_conversions' => 5,
        'attributed_revenue' => 800.00,
        'total_weight' => 5.0,
        'summarized_at' => now(),
    ]);

    $efforts = $this->service->getAttributionByEffort($this->start, $this->end, 'first_touch');

    expect($efforts)->toHaveCount(1);
    expect($efforts[0]['effort_name'])->toBe('Newsletter Campaign');
    expect($efforts[0]['attributed_conversions'])->toBe(5);
    expect($efforts[0]['attributed_revenue'])->toBe(800.00);
});

it('returns zero values for empty workspace', function () {
    $emptyWorkspace = new Workspace(['name' => 'Empty']);
    $emptyWorkspace->organization_id = $this->org->id;
    $emptyWorkspace->save();

    $emptyService = MetricsService::forWorkspace($emptyWorkspace->id);

    $campaign = $emptyService->getCampaignMetrics($this->start, $this->end);
    expect($campaign['sent'])->toBe(0);
    expect($campaign['open_rate'])->toBe(0.00);

    $conversion = $emptyService->getConversionMetrics($this->start, $this->end);
    expect($conversion['conversions'])->toBe(0);

    $trend = $emptyService->getDailyTrend($this->start, $this->end);
    expect(array_sum($trend['sent']))->toBe(0);

    $attribution = $emptyService->getAttributionSummary($this->start, $this->end);
    expect($attribution['attributed_conversions'])->toBe(0);
});

it('percentChange handles edge cases', function () {
    expect(MetricsService::percentChange(0, 0))->toBe(0.00);
    expect(MetricsService::percentChange(0, 100))->toBe(100.00);
    expect(MetricsService::percentChange(100, 0))->toBe(-100.00);
    expect(MetricsService::percentChange(100, 150))->toBe(50.00);
    expect(MetricsService::percentChange(200, 100))->toBe(-50.00);
});
