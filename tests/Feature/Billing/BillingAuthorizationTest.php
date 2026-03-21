<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Setup roles and permissions
    $billingPerm = Permission::findOrCreate('billing', 'web');
    $viewPerm = Permission::findOrCreate('view', 'web');
    $managePerm = Permission::findOrCreate('manage', 'web');

    $ownerRole = Role::findOrCreate('owner', 'web');
    $ownerRole->givePermissionTo([$billingPerm, $viewPerm, $managePerm]);

    $adminRole = Role::findOrCreate('admin', 'web');
    $adminRole->givePermissionTo([$viewPerm, $managePerm]);

    $editorRole = Role::findOrCreate('editor', 'web');
    $editorRole->givePermissionTo([$viewPerm]);

    $viewerRole = Role::findOrCreate('viewer', 'web');
    $viewerRole->givePermissionTo([$viewPerm]);

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
});

it('allows owner to access billing actions', function () {
    $owner = User::factory()->create(['current_organization_id' => $this->org->id]);
    $this->org->users()->attach($owner->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $owner->assignRole('owner');

    // Owner should be able to reach billing routes (validation errors expected, not 403)
    $this->actingAs($owner)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('billing.checkout'), [])
        ->assertSessionHasErrors(); // Validation errors, not 403
});

it('blocks viewer from billing actions', function () {
    $viewer = User::factory()->create(['current_organization_id' => $this->org->id]);
    $this->org->users()->attach($viewer->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');

    $this->actingAs($viewer)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('billing.checkout'), ['plan_id' => $this->plan->id, 'billing_period' => 'monthly'])
        ->assertForbidden();
});

it('blocks editor from billing actions', function () {
    $editor = User::factory()->create(['current_organization_id' => $this->org->id]);
    $this->org->users()->attach($editor->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $editor->assignRole('editor');

    $this->actingAs($editor)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('billing.checkout'), ['plan_id' => $this->plan->id, 'billing_period' => 'monthly'])
        ->assertForbidden();
});

it('blocks admin from billing actions', function () {
    $admin = User::factory()->create(['current_organization_id' => $this->org->id]);
    $this->org->users()->attach($admin->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('billing.checkout'), ['plan_id' => $this->plan->id, 'billing_period' => 'monthly'])
        ->assertForbidden();
});

it('requires authentication for billing routes', function () {
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('billing.checkout'))
        ->assertRedirect(route('login'));
});
