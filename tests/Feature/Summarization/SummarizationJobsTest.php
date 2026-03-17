<?php

use App\Jobs\Summarization\RunSummarization;
use App\Jobs\Summarization\SummarizeAllWorkspaces;
use App\Jobs\Summarization\SummarizeCampaigns;
use App\Jobs\Summarization\SummarizeConversions;
use App\Jobs\Summarization\SummarizeWorkspace;
use App\Models\CampaignEmail;
use App\Models\ConversionSale;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

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

// ── SummarizeCampaigns ────────────────────────────────────────────────

it('aggregates campaign_emails into summary_campaign_daily', function () {
    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'c1',
        'name' => 'Email 1',
        'sent' => 100,
        'delivered' => 90,
        'opens' => 30,
        'clicks' => 10,
        'platform_revenue' => 50.00,
        'sent_at' => '2026-03-01 10:00:00',
    ]);

    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'c2',
        'name' => 'Email 2',
        'sent' => 200,
        'delivered' => 190,
        'opens' => 60,
        'clicks' => 20,
        'platform_revenue' => 100.00,
        'sent_at' => '2026-03-01 14:00:00',
    ]);

    $job = new SummarizeCampaigns($this->workspace->id);
    $job->handle();

    $summary = DB::table('summary_campaign_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-03-01')
        ->first();

    expect($summary)->not->toBeNull();
    expect($summary->campaigns_count)->toBe(2);
    expect($summary->sent)->toBe(300);
    expect($summary->delivered)->toBe(280);
    expect($summary->opens)->toBe(90);
    expect($summary->clicks)->toBe(30);
    expect((float) $summary->platform_revenue)->toBe(150.00);
});

it('aggregates campaigns by platform into summary_campaign_by_platform', function () {
    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'ac1',
        'name' => 'AC Email',
        'sent' => 100,
        'sent_at' => '2026-03-01',
    ]);

    $job = new SummarizeCampaigns($this->workspace->id);
    $job->handle();

    $summary = DB::table('summary_campaign_by_platform')
        ->where('workspace_id', $this->workspace->id)
        ->where('platform', 'activecampaign')
        ->first();

    expect($summary)->not->toBeNull();
    expect($summary->campaigns_count)->toBe(1);
    expect($summary->sent)->toBe(100);
});

// ── SummarizeConversions ──────────────────────────────────────────────

it('aggregates conversion_sales into summary_conversion_daily', function () {
    ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'cv1',
        'revenue' => 100.00,
        'payout' => 50.00,
        'cost' => 10.00,
        'converted_at' => '2026-03-01',
    ]);

    ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'cv2',
        'revenue' => 200.00,
        'payout' => 75.00,
        'cost' => 20.00,
        'converted_at' => '2026-03-01',
    ]);

    $job = new SummarizeConversions($this->workspace->id);
    $job->handle();

    $summary = DB::table('summary_conversion_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-03-01')
        ->first();

    expect($summary)->not->toBeNull();
    expect($summary->conversions_count)->toBe(2);
    expect((float) $summary->revenue)->toBe(300.00);
    expect((float) $summary->payout)->toBe(125.00);
    expect((float) $summary->cost)->toBe(30.00);
});

// ── SummarizeWorkspace ────────────────────────────────────────────────

it('combines campaign and conversion summaries into workspace daily', function () {
    DB::table('summary_campaign_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'campaigns_count' => 5,
        'sent' => 500,
        'opens' => 100,
        'clicks' => 50,
        'summarized_at' => now(),
    ]);

    DB::table('summary_conversion_daily')->insert([
        'workspace_id' => $this->workspace->id,
        'summary_date' => '2026-03-01',
        'conversions_count' => 10,
        'revenue' => 1000.00,
        'cost' => 200.00,
        'summarized_at' => now(),
    ]);

    $job = new SummarizeWorkspace($this->workspace->id);
    $job->handle();

    $summary = DB::table('summary_workspace_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-03-01')
        ->first();

    expect($summary)->not->toBeNull();
    expect($summary->campaigns_count)->toBe(5);
    expect($summary->sent)->toBe(500);
    expect($summary->opens)->toBe(100);
    expect($summary->clicks)->toBe(50);
    expect($summary->conversions_count)->toBe(10);
    expect((float) $summary->revenue)->toBe(1000.00);
    expect((float) $summary->cost)->toBe(200.00);
});

// ── Incremental Summarization ─────────────────────────────────────────

it('incremental campaign summarization only processes updated records', function () {
    $oldEmail = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'old1',
        'name' => 'Old Email',
        'sent' => 100,
        'sent_at' => '2026-02-01',
    ]);

    // Manually backdate the updated_at
    DB::table('campaign_emails')->where('id', $oldEmail->id)->update(['updated_at' => '2026-02-01 00:00:00']);

    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'new1',
        'name' => 'New Email',
        'sent' => 200,
        'sent_at' => '2026-03-01',
    ]);

    // Incremental run since March
    $job = new SummarizeCampaigns($this->workspace->id, Carbon::parse('2026-03-01'));
    $job->handle();

    // Only March data should be summarized
    $marchSummary = DB::table('summary_campaign_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-03-01')
        ->first();

    $febSummary = DB::table('summary_campaign_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-02-01')
        ->first();

    expect($marchSummary)->not->toBeNull();
    expect($marchSummary->sent)->toBe(200);
    expect($febSummary)->toBeNull();
});

// ── Full Re-Summarization ─────────────────────────────────────────────

it('full campaign summarization processes all dates when since is null', function () {
    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'e1',
        'sent' => 100,
        'sent_at' => '2026-01-01',
    ]);

    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'e2',
        'sent' => 200,
        'sent_at' => '2026-03-01',
    ]);

    $job = new SummarizeCampaigns($this->workspace->id);
    $job->handle();

    expect(DB::table('summary_campaign_daily')->where('workspace_id', $this->workspace->id)->count())->toBe(2);
});

// ── Upsert Idempotency ───────────────────────────────────────────────

it('upsert replaces existing summary rows on re-summarization', function () {
    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'u1',
        'sent' => 100,
        'sent_at' => '2026-03-01',
    ]);

    $job = new SummarizeCampaigns($this->workspace->id);
    $job->handle();

    expect(DB::table('summary_campaign_daily')->where('workspace_id', $this->workspace->id)->first()->sent)->toBe(100);

    // Add more data and re-summarize
    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'u2',
        'sent' => 200,
        'sent_at' => '2026-03-01',
    ]);

    $job->handle();

    // Should have single row with updated totals
    $count = DB::table('summary_campaign_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-03-01')
        ->count();
    expect($count)->toBe(1);

    $summary = DB::table('summary_campaign_daily')
        ->where('workspace_id', $this->workspace->id)
        ->where('summary_date', '2026-03-01')
        ->first();
    expect($summary->sent)->toBe(300);
});

// ── SummarizeAllWorkspaces ────────────────────────────────────────────

it('dispatches summarization for workspaces with active integrations', function () {
    Bus::fake([RunSummarization::class]);

    $job = new SummarizeAllWorkspaces;
    $job->handle();

    Bus::assertDispatched(RunSummarization::class, function ($job) {
        return $job->workspaceId === $this->workspace->id;
    });
});

it('respects workspace limit configuration', function () {
    Bus::fake([RunSummarization::class]);
    config(['summarization.workspace_limit' => 1]);

    // Create a second workspace with active integration
    $workspace2 = new Workspace(['name' => 'Second']);
    $workspace2->organization_id = $this->org->id;
    $workspace2->save();

    $integration2 = new Integration([
        'name' => 'Integration 2',
        'platform' => 'expertsender',
        'data_types' => ['campaign_emails'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
    ]);
    $integration2->workspace_id = $workspace2->id;
    $integration2->organization_id = $this->org->id;
    $integration2->save();

    $job = new SummarizeAllWorkspaces;
    $job->handle();

    Bus::assertDispatched(RunSummarization::class, 1);
});

// ── RunSummarization: Unique ID ───────────────────────────────────────

it('RunSummarization has unique ID scoped by workspace', function () {
    $job = new RunSummarization($this->workspace->id);
    expect($job->uniqueId())->toBe((string) $this->workspace->id);
    expect($job->uniqueFor)->toBe(1200);
});

// ── Job Config ────────────────────────────────────────────────────────

it('all summarization jobs have correct retry and timeout config', function () {
    $campaigns = new SummarizeCampaigns(1);
    expect($campaigns->tries)->toBe(2);
    expect($campaigns->timeout)->toBe(300);
    expect($campaigns->failOnTimeout)->toBeTrue();
    expect($campaigns->backoff())->toBe([60]);

    $conversions = new SummarizeConversions(1);
    expect($conversions->tries)->toBe(2);
    expect($conversions->timeout)->toBe(300);

    $workspace = new SummarizeWorkspace(1);
    expect($workspace->tries)->toBe(2);
    expect($workspace->timeout)->toBe(300);

    $run = new RunSummarization(1);
    expect($run->tries)->toBe(2);
    expect($run->timeout)->toBe(300);
});

// ── last_summarized_at ────────────────────────────────────────────────

it('workspace has last_summarized_at column', function () {
    expect(Schema::hasColumn('workspaces', 'last_summarized_at'))->toBeTrue();

    $this->workspace->last_summarized_at = now();
    $this->workspace->save();
    $this->workspace->refresh();

    expect($this->workspace->last_summarized_at)->toBeInstanceOf(Carbon::class);
});

// ── Summarization Queue ───────────────────────────────────────────────

it('all summarization jobs use the summarization queue', function () {
    $campaigns = new SummarizeCampaigns(1);
    expect($campaigns->queue)->toBe('summarization');

    $conversions = new SummarizeConversions(1);
    expect($conversions->queue)->toBe('summarization');

    $workspace = new SummarizeWorkspace(1);
    expect($workspace->queue)->toBe('summarization');

    $run = new RunSummarization(1);
    expect($run->queue)->toBe('summarization');

    $all = new SummarizeAllWorkspaces;
    expect($all->queue)->toBe('summarization');
});
