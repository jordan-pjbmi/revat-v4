<?php

use Illuminate\Support\Facades\Schema;

// ── summary_campaign_daily ────────────────────────────────────────────

it('creates summary_campaign_daily table with correct columns', function () {
    expect(Schema::hasTable('summary_campaign_daily'))->toBeTrue();

    $columns = Schema::getColumnListing('summary_campaign_daily');
    expect($columns)->toContain('workspace_id');
    expect($columns)->toContain('summary_date');
    expect($columns)->toContain('campaigns_count');
    expect($columns)->toContain('sent');
    expect($columns)->toContain('delivered');
    expect($columns)->toContain('bounced');
    expect($columns)->toContain('complaints');
    expect($columns)->toContain('unsubscribes');
    expect($columns)->toContain('opens');
    expect($columns)->toContain('unique_opens');
    expect($columns)->toContain('clicks');
    expect($columns)->toContain('unique_clicks');
    expect($columns)->toContain('platform_revenue');
    expect($columns)->toContain('summarized_at');
    expect($columns)->not->toContain('id');
});

// ── summary_conversion_daily ──────────────────────────────────────────

it('creates summary_conversion_daily table with correct columns', function () {
    expect(Schema::hasTable('summary_conversion_daily'))->toBeTrue();

    $columns = Schema::getColumnListing('summary_conversion_daily');
    expect($columns)->toContain('workspace_id');
    expect($columns)->toContain('summary_date');
    expect($columns)->toContain('conversions_count');
    expect($columns)->toContain('revenue');
    expect($columns)->toContain('payout');
    expect($columns)->toContain('cost');
    expect($columns)->toContain('summarized_at');
    expect($columns)->not->toContain('id');
});

// ── summary_campaign_by_platform ──────────────────────────────────────

it('creates summary_campaign_by_platform table with correct columns', function () {
    expect(Schema::hasTable('summary_campaign_by_platform'))->toBeTrue();

    $columns = Schema::getColumnListing('summary_campaign_by_platform');
    expect($columns)->toContain('workspace_id');
    expect($columns)->toContain('platform');
    expect($columns)->toContain('summary_date');
    expect($columns)->toContain('campaigns_count');
    expect($columns)->toContain('sent');
    expect($columns)->toContain('platform_revenue');
    expect($columns)->toContain('summarized_at');
    expect($columns)->not->toContain('id');
});

// ── summary_attribution_daily ─────────────────────────────────────────

it('creates summary_attribution_daily table with correct columns', function () {
    expect(Schema::hasTable('summary_attribution_daily'))->toBeTrue();

    $columns = Schema::getColumnListing('summary_attribution_daily');
    expect($columns)->toContain('workspace_id');
    expect($columns)->toContain('summary_date');
    expect($columns)->toContain('model');
    expect($columns)->toContain('attributed_conversions');
    expect($columns)->toContain('attributed_revenue');
    expect($columns)->toContain('total_weight');
    expect($columns)->toContain('summarized_at');
    expect($columns)->not->toContain('id');
});

// ── summary_attribution_by_effort ─────────────────────────────────────

it('creates summary_attribution_by_effort table with correct columns', function () {
    expect(Schema::hasTable('summary_attribution_by_effort'))->toBeTrue();

    $columns = Schema::getColumnListing('summary_attribution_by_effort');
    expect($columns)->toContain('workspace_id');
    expect($columns)->toContain('effort_id');
    expect($columns)->toContain('summary_date');
    expect($columns)->toContain('model');
    expect($columns)->toContain('attributed_conversions');
    expect($columns)->toContain('attributed_revenue');
    expect($columns)->toContain('total_weight');
    expect($columns)->toContain('summarized_at');
    expect($columns)->not->toContain('id');
});

// ── summary_attribution_by_campaign ──────────────────────────────────

it('creates summary_attribution_by_campaign table with correct columns', function () {
    expect(Schema::hasTable('summary_attribution_by_campaign'))->toBeTrue();

    $columns = Schema::getColumnListing('summary_attribution_by_campaign');
    expect($columns)->toContain('workspace_id');
    expect($columns)->toContain('campaign_type');
    expect($columns)->toContain('campaign_id');
    expect($columns)->toContain('summary_date');
    expect($columns)->toContain('model');
    expect($columns)->toContain('attributed_conversions');
    expect($columns)->toContain('attributed_revenue');
    expect($columns)->toContain('total_weight');
    expect($columns)->toContain('summarized_at');
    expect($columns)->not->toContain('id');
});

// ── summary_workspace_daily ───────────────────────────────────────────

it('creates summary_workspace_daily table with correct columns', function () {
    expect(Schema::hasTable('summary_workspace_daily'))->toBeTrue();

    $columns = Schema::getColumnListing('summary_workspace_daily');
    expect($columns)->toContain('workspace_id');
    expect($columns)->toContain('summary_date');
    expect($columns)->toContain('campaigns_count');
    expect($columns)->toContain('sent');
    expect($columns)->toContain('opens');
    expect($columns)->toContain('clicks');
    expect($columns)->toContain('conversions_count');
    expect($columns)->toContain('revenue');
    expect($columns)->toContain('cost');
    expect($columns)->toContain('summarized_at');
    expect($columns)->not->toContain('id');
});

// ── Reversibility ─────────────────────────────────────────────────────

it('can rollback summary table migrations', function () {
    expect(Schema::hasTable('summary_campaign_daily'))->toBeTrue();
    expect(Schema::hasTable('summary_workspace_daily'))->toBeTrue();
    expect(Schema::hasTable('summary_attribution_by_campaign'))->toBeTrue();

    // Roll back all migrations from summary tables onwards (11 total)
    Artisan::call('migrate:rollback', ['--step' => 11]);

    expect(Schema::hasTable('summary_campaign_daily'))->toBeFalse();
    expect(Schema::hasTable('summary_conversion_daily'))->toBeFalse();
    expect(Schema::hasTable('summary_campaign_by_platform'))->toBeFalse();
    expect(Schema::hasTable('summary_attribution_daily'))->toBeFalse();
    expect(Schema::hasTable('summary_attribution_by_effort'))->toBeFalse();
    expect(Schema::hasTable('summary_attribution_by_campaign'))->toBeFalse();
    expect(Schema::hasTable('summary_workspace_daily'))->toBeFalse();

    // Re-migrate
    Artisan::call('migrate');

    expect(Schema::hasTable('summary_campaign_daily'))->toBeTrue();
    expect(Schema::hasTable('summary_workspace_daily'))->toBeTrue();
    expect(Schema::hasTable('summary_attribution_by_campaign'))->toBeTrue();
});
