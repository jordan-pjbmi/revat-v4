<?php

use App\Models\Organization;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has all expected columns on plans table', function () {
    expect(Schema::hasColumns('plans', [
        'id', 'name', 'slug', 'stripe_price_monthly', 'stripe_price_yearly',
        'max_workspaces', 'max_integrations_per_workspace', 'max_users',
        'is_visible', 'sort_order', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('has plan_id FK on organizations table', function () {
    expect(Schema::hasColumn('organizations', 'plan_id'))->toBeTrue();
});

it('has all expected columns on daily_usages table', function () {
    expect(Schema::hasColumns('daily_usages', [
        'id', 'organization_id', 'workspace_id', 'recorded_on',
        'campaigns_synced', 'conversions_synced', 'active_integrations',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('enforces unique constraint on daily_usages workspace_id and recorded_on', function () {
    $org = Organization::create(['name' => 'Test Org']);
    $workspace = $org->workspaces()->create(['name' => 'WS']);

    DB::table('daily_usages')->insert([
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'recorded_on' => '2026-03-14',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('daily_usages')->insert([
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'recorded_on' => '2026-03-14',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('returns visible plans ordered by sort_order', function () {
    Plan::factory()->create(['is_visible' => true, 'sort_order' => 2]);
    Plan::factory()->create(['is_visible' => true, 'sort_order' => 0]);
    Plan::factory()->create(['is_visible' => false, 'sort_order' => 1]);
    Plan::factory()->create(['is_visible' => true, 'sort_order' => 1]);

    $visible = Plan::visible()->get();

    expect($visible)->toHaveCount(3)
        ->and($visible->pluck('sort_order')->all())->toBe([0, 1, 2]);
});

it('finds plan by slug', function () {
    Plan::factory()->create(['slug' => 'growth-plan']);

    $plan = Plan::bySlug('growth-plan')->first();

    expect($plan)->not->toBeNull()
        ->and($plan->slug)->toBe('growth-plan');
});

it('creates default plans from PlanSeeder', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::count())->toBe(5);

    $visibleSlugs = Plan::visible()->pluck('slug')->all();
    expect($visibleSlugs)->toBe(['free', 'starter', 'growth', 'agency']);

    // Alpha plan exists but is hidden
    expect(Plan::where('slug', 'alpha')->where('is_visible', false)->exists())->toBeTrue();
});

it('has organizations relationship', function () {
    $plan = Plan::factory()->create();
    $org = Organization::create(['name' => 'Test Org']);
    $org->plan_id = $plan->id;
    $org->save();

    expect($plan->organizations)->toHaveCount(1)
        ->and($plan->organizations->first()->id)->toBe($org->id);
});

it('casts plan columns correctly', function () {
    $plan = Plan::factory()->create([
        'is_visible' => true,
        'max_workspaces' => 5,
        'max_integrations_per_workspace' => 3,
        'max_users' => 10,
    ]);

    expect($plan->is_visible)->toBeBool()
        ->and($plan->max_workspaces)->toBeInt()
        ->and($plan->max_integrations_per_workspace)->toBeInt()
        ->and($plan->max_users)->toBeInt();
});

it('associates organization with plan', function () {
    $plan = Plan::factory()->free()->create();
    $org = Organization::create(['name' => 'My Org']);
    $org->plan_id = $plan->id;
    $org->save();

    expect($org->fresh()->plan->slug)->toBe('free');
});
