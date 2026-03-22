<?php

use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Dashboard\WidgetDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);

    $this->workspace = new Workspace(['name' => 'Test Workspace']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->otherWorkspace = new Workspace(['name' => 'Other Workspace']);
    $this->otherWorkspace->organization_id = $this->org->id;
    $this->otherWorkspace->is_default = false;
    $this->otherWorkspace->save();

    $this->start = Carbon::parse('2026-03-01');
    $this->end = Carbon::parse('2026-03-07');

    // Seed 7 days of campaign data for primary workspace
    for ($i = 0; $i < 7; $i++) {
        $date = Carbon::parse('2026-03-01')->addDays($i)->toDateString();
        DB::table('summary_campaign_daily')->insert([
            'workspace_id' => $this->workspace->id,
            'summary_date' => $date,
            'campaigns_count' => 1,
            'sent' => 1000,
            'delivered' => 950,
            'bounced' => 20,
            'complaints' => 2,
            'unsubscribes' => 5,
            'opens' => 400,
            'unique_opens' => 300,
            'clicks' => 100,
            'unique_clicks' => 80,
            'platform_revenue' => 500.00,
            'summarized_at' => now(),
        ]);
    }

    // Seed 7 days of conversion data for primary workspace
    for ($i = 0; $i < 7; $i++) {
        $date = Carbon::parse('2026-03-01')->addDays($i)->toDateString();
        DB::table('summary_conversion_daily')->insert([
            'workspace_id' => $this->workspace->id,
            'summary_date' => $date,
            'conversions_count' => 10,
            'revenue' => 2000.00,
            'payout' => 500.00,
            'cost' => 400.00,
            'summarized_at' => now(),
        ]);
    }

    // Seed campaign_by_platform data for primary workspace
    for ($i = 0; $i < 7; $i++) {
        $date = Carbon::parse('2026-03-01')->addDays($i)->toDateString();
        DB::table('summary_campaign_by_platform')->insert([
            'workspace_id' => $this->workspace->id,
            'platform' => 'activecampaign',
            'summary_date' => $date,
            'campaigns_count' => 1,
            'sent' => 800,
            'delivered' => 760,
            'bounced' => 10,
            'complaints' => 1,
            'unsubscribes' => 2,
            'opens' => 300,
            'unique_opens' => 250,
            'clicks' => 80,
            'unique_clicks' => 60,
            'platform_revenue' => 300.00,
            'summarized_at' => now(),
        ]);
    }

    // Seed data for other workspace to test scoping
    DB::table('summary_campaign_daily')->insert([
        'workspace_id' => $this->otherWorkspace->id,
        'summary_date' => '2026-03-01',
        'campaigns_count' => 99,
        'sent' => 99999,
        'delivered' => 99000,
        'bounced' => 100,
        'complaints' => 10,
        'unsubscribes' => 50,
        'opens' => 50000,
        'unique_opens' => 40000,
        'clicks' => 10000,
        'unique_clicks' => 8000,
        'platform_revenue' => 99999.00,
        'summarized_at' => now(),
    ]);

    DB::table('summary_conversion_daily')->insert([
        'workspace_id' => $this->otherWorkspace->id,
        'summary_date' => '2026-03-01',
        'conversions_count' => 9999,
        'revenue' => 99999.00,
        'payout' => 9999.00,
        'cost' => 9999.00,
        'summarized_at' => now(),
    ]);

    $this->service = WidgetDataService::forWorkspace($this->workspace->id);
});

// ── fetchMetric ──────────────────────────────────────────────────────────────

it('fetchMetric returns correct structure', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    expect($result)->toHaveKeys(['value', 'previous', 'change', 'format', 'label']);
    expect($result['format'])->toBe('number');
    expect($result['label'])->toBe('Total Sent');
});

it('fetchMetric sums values over the date range', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    // 7 days × 1000 sent = 7000
    expect($result['value'])->toBe(7000.0);
});

it('fetchMetric uses conversions_count column for conversions measure', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'conversion_metrics', 'measure' => 'conversions'],
        $this->start,
        $this->end,
    );

    // 7 days × 10 conversions
    expect($result['value'])->toBe(70.0);
    expect($result['format'])->toBe('number');
});

it('fetchMetric uses platform_revenue column for platform_breakdown revenue', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'platform_breakdown', 'measure' => 'revenue'],
        $this->start,
        $this->end,
    );

    // 7 days × 300.00 platform_revenue
    expect($result['value'])->toBe(2100.0);
});

it('fetchMetric calculates previous period comparison', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    // Previous period (Feb 22 - Feb 28) has no data → previous = 0
    expect($result['previous'])->toBe(0.0);
    expect($result['change'])->toBe(100.0); // zero-to-nonzero = 100%
});

it('fetchMetric computes open_rate correctly', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'campaign_metrics', 'measure' => 'open_rate'],
        $this->start,
        $this->end,
    );

    // unique_opens=300, sent=1000 per day → 300/1000*100 = 30.00
    expect($result['value'])->toBe(30.0);
    expect($result['format'])->toBe('percent');
});

it('fetchMetric computes click_rate correctly', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'campaign_metrics', 'measure' => 'click_rate'],
        $this->start,
        $this->end,
    );

    // unique_clicks=80, sent=1000 per day → 80/1000*100 = 8.00
    expect($result['value'])->toBe(8.0);
    expect($result['format'])->toBe('percent');
});

it('fetchMetric computes roas correctly', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'conversion_metrics', 'measure' => 'roas'],
        $this->start,
        $this->end,
    );

    // revenue=2000, cost=400 per day → 2000/400 = 5.00
    expect($result['value'])->toBe(5.0);
    expect($result['format'])->toBe('decimal');
});

// ── fetchTrend ───────────────────────────────────────────────────────────────

it('fetchTrend returns correct structure', function () {
    $result = $this->service->fetchTrend(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    expect($result)->toHaveKeys(['labels', 'datasets']);
    expect($result['datasets'])->toHaveCount(1);
    expect($result['datasets'][0])->toHaveKeys(['label', 'data']);
});

it('fetchTrend returns daily labels for the date range', function () {
    $result = $this->service->fetchTrend(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    expect($result['labels'])->toHaveCount(7);
    expect($result['labels'][0])->toBe('2026-03-01');
    expect($result['labels'][6])->toBe('2026-03-07');
});

it('fetchTrend backfills missing dates with zero', function () {
    // Query a range outside our seeded data
    $result = $this->service->fetchTrend(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        Carbon::parse('2026-02-28'),
        Carbon::parse('2026-03-02'),
    );

    expect($result['labels'])->toHaveCount(3);
    expect($result['datasets'][0]['data'][0])->toBe(0.0); // Feb 28 - no data
    expect($result['datasets'][0]['data'][1])->toBe(1000.0); // Mar 1
    expect($result['datasets'][0]['data'][2])->toBe(1000.0); // Mar 2
});

it('fetchTrend returns correct data for each day', function () {
    $result = $this->service->fetchTrend(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    // Each day has 1000 sent
    foreach ($result['datasets'][0]['data'] as $value) {
        expect($value)->toBe(1000.0);
    }
});

it('fetchTrend computes open_rate per day', function () {
    $result = $this->service->fetchTrend(
        ['source' => 'campaign_metrics', 'measure' => 'open_rate'],
        $this->start,
        Carbon::parse('2026-03-02'),
    );

    expect($result['labels'])->toHaveCount(2);
    // unique_opens=300, sent=1000 → 30.00%
    expect($result['datasets'][0]['data'][0])->toBe(30.0);
    expect($result['datasets'][0]['data'][1])->toBe(30.0);
});

// ── fetchGrouped ─────────────────────────────────────────────────────────────

it('fetchGrouped returns correct structure', function () {
    $result = $this->service->fetchGrouped(
        ['source' => 'campaign_metrics', 'measure' => 'sent', 'group_by' => 'summary_date'],
        $this->start,
        $this->end,
    );

    expect($result)->toHaveKeys(['labels', 'datasets']);
    expect($result['datasets'])->toHaveCount(1);
    expect($result['datasets'][0])->toHaveKeys(['label', 'data']);
});

it('fetchGrouped groups by summary_date', function () {
    $result = $this->service->fetchGrouped(
        ['source' => 'campaign_metrics', 'measure' => 'sent', 'group_by' => 'summary_date'],
        $this->start,
        $this->end,
    );

    expect($result['labels'])->toHaveCount(7);
});

it('fetchGrouped applies limit', function () {
    $result = $this->service->fetchGrouped(
        ['source' => 'campaign_metrics', 'measure' => 'sent', 'group_by' => 'summary_date', 'limit' => 3],
        $this->start,
        $this->end,
    );

    expect($result['labels'])->toHaveCount(3);
    expect($result['datasets'][0]['data'])->toHaveCount(3);
});

it('fetchGrouped groups by platform for platform_breakdown', function () {
    $result = $this->service->fetchGrouped(
        ['source' => 'platform_breakdown', 'measure' => 'sent', 'group_by' => 'platform'],
        $this->start,
        $this->end,
    );

    expect($result['labels'])->toContain('activecampaign');
    // 7 days × 800 = 5600
    expect($result['datasets'][0]['data'][0])->toBe(5600);
});

it('fetchGrouped computed open_rate returns rates not totals', function () {
    $result = $this->service->fetchGrouped(
        ['source' => 'campaign_metrics', 'measure' => 'open_rate', 'group_by' => 'summary_date', 'limit' => 1],
        $this->start,
        $this->end,
    );

    expect($result['labels'])->toHaveCount(1);
    // 300/1000*100 = 30.00
    expect($result['datasets'][0]['data'][0])->toBe(30.0);
});

// ── fetchTable ───────────────────────────────────────────────────────────────

it('fetchTable returns correct structure', function () {
    $result = $this->service->fetchTable(
        ['source' => 'campaign_metrics'],
        $this->start,
        $this->end,
    );

    expect($result)->toHaveKeys(['columns', 'rows', 'totals']);
});

it('fetchTable only returns non-computed measures', function () {
    $result = $this->service->fetchTable(
        ['source' => 'campaign_metrics'],
        $this->start,
        $this->end,
    );

    $columnKeys = array_column($result['columns'], 'key');
    expect($columnKeys)->not->toContain('open_rate');
    expect($columnKeys)->not->toContain('click_rate');
    expect($columnKeys)->toContain('sent');
    expect($columnKeys)->toContain('opens');
    expect($columnKeys)->toContain('clicks');
});

it('fetchTable includes columns metadata with format', function () {
    $result = $this->service->fetchTable(
        ['source' => 'conversion_metrics'],
        $this->start,
        $this->end,
    );

    $columnKeys = array_column($result['columns'], 'key');
    expect($columnKeys)->toContain('conversions');
    expect($columnKeys)->toContain('revenue');
    expect($columnKeys)->toContain('cost');
    expect($columnKeys)->not->toContain('roas');

    $revenueCol = collect($result['columns'])->firstWhere('key', 'revenue');
    expect($revenueCol['format'])->toBe('currency');
});

it('fetchTable rows have date and measure values', function () {
    $result = $this->service->fetchTable(
        ['source' => 'campaign_metrics'],
        $this->start,
        $this->end,
    );

    expect($result['rows'])->toHaveCount(7);
    expect($result['rows'][0])->toHaveKey('date');
    expect($result['rows'][0]['date'])->toBe('2026-03-01');
    expect($result['rows'][0]['sent'])->toBe(1000);
});

it('fetchTable totals sum all rows', function () {
    $result = $this->service->fetchTable(
        ['source' => 'campaign_metrics'],
        $this->start,
        $this->end,
    );

    // 7 days × 1000 = 7000
    expect($result['totals']['sent'])->toBe(7000);
    expect($result['totals']['clicks'])->toBe(700);
});

it('fetchTable conversion_metrics uses conversions_count column', function () {
    $result = $this->service->fetchTable(
        ['source' => 'conversion_metrics'],
        $this->start,
        $this->end,
    );

    // 7 days × 10 conversions
    expect($result['totals']['conversions'])->toBe(70);
    // 7 days × 2000 = 14000
    expect($result['totals']['revenue'])->toBe(14000.0);
});

it('fetchTable respects limit', function () {
    $result = $this->service->fetchTable(
        ['source' => 'campaign_metrics', 'limit' => 3],
        $this->start,
        $this->end,
    );

    expect($result['rows'])->toHaveCount(3);
});

// ── Workspace Scoping ────────────────────────────────────────────────────────

it('fetchMetric excludes data from other workspaces', function () {
    $result = $this->service->fetchMetric(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    // Should only see 7 × 1000 = 7000, not the other workspace's 99999
    expect($result['value'])->toBe(7000.0);
});

it('fetchTrend excludes data from other workspaces', function () {
    $result = $this->service->fetchTrend(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    // Each day should be 1000, not 1000 + 99999
    foreach ($result['datasets'][0]['data'] as $value) {
        expect($value)->toBeLessThanOrEqual(1000.0);
    }
});

it('fetchTable excludes data from other workspaces', function () {
    $result = $this->service->fetchTable(
        ['source' => 'campaign_metrics'],
        $this->start,
        $this->end,
    );

    // Total sent should be 7000, not including other workspace
    expect($result['totals']['sent'])->toBe(7000);
});

it('fetchMetric returns zeros for empty workspace', function () {
    $emptyService = WidgetDataService::forWorkspace($this->otherWorkspace->id + 9999);

    $result = $emptyService->fetchMetric(
        ['source' => 'campaign_metrics', 'measure' => 'sent'],
        $this->start,
        $this->end,
    );

    expect($result['value'])->toBe(0.0);
    expect($result['previous'])->toBe(0.0);
    expect($result['change'])->toBe(0.0);
});
