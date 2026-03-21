<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanEnforcement\PlanEnforcementService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $billingPerm = Permission::findOrCreate('billing', 'web');
    $ownerRole = Role::findOrCreate('owner', 'web');
    $ownerRole->givePermissionTo($billingPerm);

    $this->starterPlan = Plan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'stripe_price_monthly' => 'price_starter_monthly',
        'stripe_price_yearly' => 'price_starter_yearly',
        'max_users' => 3,
        'max_workspaces' => 2,
        'max_integrations_per_workspace' => 5,
        'is_visible' => true,
        'sort_order' => 1,
    ]);

    $this->growthPlan = Plan::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'stripe_price_monthly' => 'price_growth_monthly',
        'stripe_price_yearly' => 'price_growth_yearly',
        'max_users' => 10,
        'max_workspaces' => 5,
        'max_integrations_per_workspace' => 20,
        'is_visible' => true,
        'sort_order' => 2,
    ]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->org->plan_id = $this->growthPlan->id;
    $this->org->save();

    $this->user = User::factory()->create(['current_organization_id' => $this->org->id]);
    $this->org->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('validates swap request parameters', function () {
    $this->actingAs($this->user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->put(route('billing.swap'), [])
        ->assertSessionHasErrors(['plan_id', 'billing_period']);
});

it('blocks downgrade when usage exceeds target plan limits', function () {
    // Add users beyond starter plan limit (3)
    for ($i = 0; $i < 5; $i++) {
        $u = User::factory()->create();
        $this->org->users()->attach($u->id);
    }

    // Create a fake active subscription
    DB::table('subscriptions')->insert([
        'organization_id' => $this->org->id,
        'type' => 'default',
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_growth_monthly',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->put(route('billing.swap'), [
            'plan_id' => $this->starterPlan->id,
            'billing_period' => 'monthly',
        ]);

    $response->assertRedirect(route('billing'));
    $response->assertSessionHas('error');

    // plan_id should not have changed
    expect($this->org->fresh()->plan_id)->toBe($this->growthPlan->id);
});

it('allows downgrade when usage is within target plan limits', function () {
    // Only 1 user (owner) attached - within starter limit of 3
    // Verify the enforcement check passes (actual Stripe swap is tested in integration)
    $enforcement = app(PlanEnforcementService::class);
    expect($enforcement->canDowngradeTo($this->org, $this->starterPlan))->toBeTrue();
});
