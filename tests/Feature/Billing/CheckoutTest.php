<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Setup roles and permissions
    $billingPerm = Permission::findOrCreate('billing', 'web');
    $ownerRole = Role::findOrCreate('owner', 'web');
    $ownerRole->givePermissionTo($billingPerm);

    $this->plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'stripe_price_monthly' => 'price_pro_monthly',
        'stripe_price_yearly' => 'price_pro_yearly',
        'max_users' => 10,
        'max_workspaces' => 5,
        'max_integrations_per_workspace' => 20,
        'is_visible' => true,
        'sort_order' => 1,
    ]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->user = User::factory()->create(['current_organization_id' => $this->org->id]);
    $this->org->users()->attach($this->user->id);

    // Assign owner role scoped to org
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('blocks checkout if organization already has active subscription', function () {
    // Create a fake subscription
    DB::table('subscriptions')->insert([
        'organization_id' => $this->org->id,
        'type' => 'default',
        'stripe_id' => 'sub_existing',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('billing.checkout'), [
            'plan_id' => $this->plan->id,
            'billing_period' => 'monthly',
        ]);

    $response->assertRedirect(route('billing'));
    $response->assertSessionHas('error');
});

it('validates checkout request parameters', function () {
    $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('billing.checkout'), [])
        ->assertSessionHasErrors(['plan_id', 'billing_period']);
});

it('validates billing period value', function () {
    $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('billing.checkout'), [
            'plan_id' => $this->plan->id,
            'billing_period' => 'weekly',
        ])
        ->assertSessionHasErrors('billing_period');
});
